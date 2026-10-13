<?php

declare(strict_types=1);

use Firefly\Webhooks\Exception\WebhookRejectedException;
use Firefly\Webhooks\Provider\WebhookProviderSettings;
use Firefly\Webhooks\Signature\GitHubSignatureValidator;
use Firefly\Webhooks\Signature\HmacSignatureValidator;
use Firefly\Webhooks\Signature\NoOpSignatureValidator;
use Firefly\Webhooks\Signature\SignatureValidatorFactory;
use Firefly\Webhooks\Signature\StripeSignatureValidator;
use Firefly\Webhooks\Signature\TwilioSignatureValidator;
use Firefly\Webhooks\Tests\Support\WebhookRequests;

const W4_NOW = 1_760_000_000;

function w4Clock(): Closure
{
    return static fn (): int => W4_NOW;
}

function w4Rejection(Closure $verify): WebhookRejectedException
{
    try {
        $verify();
    } catch (WebhookRejectedException $e) {
        return $e;
    }

    throw new RuntimeException('the request was accepted');
}

it('accepts a generic HMAC-SHA256 over the body and rejects a tampered body', function () {
    $body = '{"type":"order.created","id":1}';
    $signature = 'sha256='.hash_hmac('sha256', $body, 'secret');
    $validator = new HmacSignatureValidator('secret');

    $validator->verify(WebhookRequests::make('acme', $body, ['X-Signature' => $signature]));

    $rejected = w4Rejection(fn () => $validator->verify(WebhookRequests::make('acme', $body.' ', ['X-Signature' => $signature])));
    expect($rejected->errorCode())->toBe('WEBHOOK_SIGNATURE_INVALID')
        ->and($rejected->httpStatus())->toBe(401)
        ->and($rejected->extensions())->toBe(['provider' => 'acme']);
});

it('rejects a missing header and a missing prefix without comparing anything', function () {
    $validator = new HmacSignatureValidator('secret');

    expect(w4Rejection(fn () => $validator->verify(WebhookRequests::make('acme', '{}')))->getMessage())->toContain('X-Signature header is missing')
        ->and(w4Rejection(fn () => $validator->verify(WebhookRequests::make('acme', '{}', ['X-Signature' => hash_hmac('sha256', '{}', 'secret')])))->getMessage())->toContain('sha256=');
});

it('signs "<timestamp>.<body>" when a timestamp header is configured, inside the replay window only', function () {
    $body = '{"a":1}';
    $validator = new HmacSignatureValidator('secret', 'X-Firefly-Signature', 'sha256=', 'sha256', 'hex', 'X-Firefly-Timestamp', 300.0, w4Clock());
    $sign = static fn (int $t): array => ['X-Firefly-Timestamp' => (string) $t, 'X-Firefly-Signature' => 'sha256='.hash_hmac('sha256', $t.'.'.$body, 'secret')];

    $validator->verify(WebhookRequests::make('acme', $body, $sign(W4_NOW - 299)));

    $replayed = w4Rejection(fn () => $validator->verify(WebhookRequests::make('acme', $body, $sign(W4_NOW - 301))));
    expect($replayed->errorCode())->toBe('WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE')->and($replayed->httpStatus())->toBe(401);
});

it('supports base64 encodings and other algorithms', function () {
    $body = 'raw';
    $validator = new HmacSignatureValidator('secret', 'X-Sig', '', 'sha512', 'base64');

    $validator->verify(WebhookRequests::make('acme', $body, ['X-Sig' => base64_encode(hash_hmac('sha512', $body, 'secret', true))]));

    expect(true)->toBeTrue();
});

it('verifies Stripe-Signature with any matching v1 and enforces the tolerance', function () {
    $body = '{"id":"evt_1","type":"payment_intent.succeeded"}';
    $t = W4_NOW - 10;
    $good = hash_hmac('sha256', $t.'.'.$body, 'whsec');
    $validator = new StripeSignatureValidator('whsec', 'Stripe-Signature', 300.0, w4Clock());

    $validator->verify(WebhookRequests::make('stripe', $body, ['Stripe-Signature' => "t={$t},v1=deadbeef,v1={$good}"]));

    $stale = W4_NOW - 1000;
    $staleSig = hash_hmac('sha256', $stale.'.'.$body, 'whsec');
    expect(w4Rejection(fn () => $validator->verify(WebhookRequests::make('stripe', $body, ['Stripe-Signature' => "t={$stale},v1={$staleSig}"])))->errorCode())->toBe('WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE')
        ->and(w4Rejection(fn () => $validator->verify(WebhookRequests::make('stripe', $body, ['Stripe-Signature' => "v1={$good}"])))->getMessage())->toContain('t=')
        ->and(w4Rejection(fn () => $validator->verify(WebhookRequests::make('stripe', $body, ['Stripe-Signature' => "t={$t},v1=00"])))->errorCode())->toBe('WEBHOOK_SIGNATURE_INVALID');
});

it('verifies GitHub X-Hub-Signature-256', function () {
    $body = '{"zen":"Keep it logically awesome."}';
    $validator = new GitHubSignatureValidator('gh-secret');

    $validator->verify(WebhookRequests::make('github', $body, ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'gh-secret')]));

    expect(w4Rejection(fn () => $validator->verify(WebhookRequests::make('github', $body, ['X-Hub-Signature-256' => 'sha1=abc'])))->errorCode())->toBe('WEBHOOK_SIGNATURE_INVALID');
});

it('verifies Twilio form callbacks over the URL plus the sorted parameters', function () {
    $url = 'https://hooks.example.com/webhooks/twilio?foo=1';
    $form = ['To' => '+18005551212', 'CallSid' => 'CA123', 'Digits' => '1234'];
    $expected = base64_encode(hash_hmac('sha1', $url.'CallSidCA123Digits1234To+18005551212', 'token', true));
    $validator = new TwilioSignatureValidator('token');

    $validator->verify(WebhookRequests::make('twilio', http_build_query($form), ['X-Twilio-Signature' => $expected], $form, $url, 'application/x-www-form-urlencoded'));

    expect(TwilioSignatureValidator::signedForm($url, $form))->toBe($url.'CallSidCA123Digits1234To+18005551212')
        ->and(w4Rejection(fn () => $validator->verify(WebhookRequests::make('twilio', 'x', ['X-Twilio-Signature' => $expected], ['To' => 'other'], $url, 'application/x-www-form-urlencoded')))->errorCode())->toBe('WEBHOOK_SIGNATURE_INVALID');
});

it('signs a repeated Twilio parameter the way twilio-php does: values de-duplicated and sorted', function () {
    $url = 'https://hooks.example.com/webhooks/twilio';

    expect(TwilioSignatureValidator::signedForm($url, ['To' => '+1', 'Media' => ['b', 'a', 'b']]))->toBe($url.'MediaaMediabTo+1');
});

it('verifies Twilio JSON callbacks through the bodySHA256 parameter', function () {
    $body = '{"EventType":"onMessageAdded"}';
    $url = 'https://hooks.example.com/webhooks/twilio?bodySHA256='.hash('sha256', $body);
    $signature = base64_encode(hash_hmac('sha1', $url, 'token', true));
    $validator = new TwilioSignatureValidator('token');

    $validator->verify(WebhookRequests::make('twilio', $body, ['X-Twilio-Signature' => $signature], [], $url));

    expect(w4Rejection(fn () => $validator->verify(WebhookRequests::make('twilio', $body.'x', ['X-Twilio-Signature' => $signature], [], $url)))->getMessage())->toContain('bodySHA256');
});

it('checks bodySHA256 whatever the content type, so a replayed JSON signature cannot carry a forged body', function () {
    $body = '{"EventType":"onMessageAdded"}';
    $url = 'https://hooks.example.com/webhooks/twilio?bodySHA256='.hash('sha256', $body);
    $signature = base64_encode(hash_hmac('sha1', $url, 'token', true));
    $validator = new TwilioSignatureValidator('token');

    $validator->verify(WebhookRequests::make('twilio', $body, ['X-Twilio-Signature' => $signature], [], $url, ''));

    foreach (['', 'text/plain', 'application/octet-stream', 'application/x-www-form-urlencoded'] as $contentType) {
        $forged = WebhookRequests::make('twilio', '{"EventType":"forged"}', ['X-Twilio-Signature' => $signature], [], $url, $contentType);

        expect(w4Rejection(fn () => $validator->verify($forged))->getMessage())->toContain('bodySHA256');
    }
});

it('rejects a Twilio request whose body is neither form-encoded nor covered by bodySHA256', function () {
    $url = 'https://hooks.example.com/webhooks/twilio';
    $signature = base64_encode(hash_hmac('sha1', $url, 'token', true));
    $validator = new TwilioSignatureValidator('token');

    $validator->verify(WebhookRequests::make('twilio', '', ['X-Twilio-Signature' => $signature], [], $url, ''));

    foreach (['', 'text/plain', 'application/json'] as $contentType) {
        $request = WebhookRequests::make('twilio', '{"EventType":"forged"}', ['X-Twilio-Signature' => $signature], [], $url, $contentType);

        expect(w4Rejection(fn () => $validator->verify($request))->errorCode())->toBe('WEBHOOK_SIGNATURE_INVALID');
    }
});

it('accepts anything with the NoOp validator, and the factory picks the validator for each kind', function () {
    (new NoOpSignatureValidator)->verify(WebhookRequests::make('open', 'anything'));
    $factory = new SignatureValidatorFactory(w4Clock());
    $kinds = [];
    foreach (['hmac', 'stripe', 'github', 'twilio', 'none'] as $kind) {
        $kinds[$kind] = $factory->for(WebhookProviderSettings::fromArray('p', ['validator' => $kind, 'secret' => 's'], 300.0))::class;
    }

    expect($kinds)->toBe([
        'hmac' => HmacSignatureValidator::class,
        'stripe' => StripeSignatureValidator::class,
        'github' => GitHubSignatureValidator::class,
        'twilio' => TwilioSignatureValidator::class,
        'none' => NoOpSignatureValidator::class,
    ]);
});
