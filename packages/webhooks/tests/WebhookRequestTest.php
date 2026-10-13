<?php

declare(strict_types=1);

use Firefly\Webhooks\Signature\TwilioSignatureValidator;
use Firefly\Webhooks\WebhookRequest;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('captures the exact URL, lower-cased headers, the raw body and form parameters', function () {
    $request = Request::create('https://api.example.com/webhooks/twilio?b=2&a=1', 'POST', ['To' => '+1'], [], [], [
        'HTTP_X_TWILIO_SIGNATURE' => 'sig',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8',
        'REMOTE_ADDR' => '10.0.0.5',
    ], 'To=%2B1');

    $webhook = WebhookRequest::fromHttp($request, 'twilio', null, 1_760_000_000);

    expect($webhook->url)->toBe('https://api.example.com/webhooks/twilio?b=2&a=1')
        ->and($webhook->header('X-Twilio-Signature'))->toBe('sig')
        ->and($webhook->body)->toBe('To=%2B1')
        ->and($webhook->form)->toBe(['To' => '+1'])
        ->and($webhook->contentType)->toBe('application/x-www-form-urlencoded')
        ->and($webhook->sourceIp)->toBe('10.0.0.5')
        ->and($webhook->receivedAt)->toBe(1_760_000_000);
});

it('signs against the configured public URL when a proxy rewrites the host', function () {
    $request = Request::create('http://10.0.0.7:8080/webhooks/twilio?x=1', 'POST');

    expect(WebhookRequest::fromHttp($request, 'twilio', 'https://api.example.com/webhooks/twilio', 0)->url)->toBe('https://api.example.com/webhooks/twilio?x=1');
});

it('reads Twilio form parameters from the raw body, so the global TrimStrings and ConvertEmptyStringsToNull middleware cannot break the signature', function () {
    $url = 'https://api.example.com/webhooks/twilio';
    $raw = 'Body=Hello+&From=%2B1&MediaUrl=&Tag=b&Tag=a';
    $signed = $url.'BodyHello From+1MediaUrlTagaTagb';
    $request = Request::create($url, 'POST', ['Body' => 'Hello ', 'From' => '+1', 'MediaUrl' => '', 'Tag' => 'a'], [], [], [
        'HTTP_X_TWILIO_SIGNATURE' => base64_encode(hash_hmac('sha1', $signed, 'token', true)),
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $raw);

    $passed = null;
    (new TrimStrings)->handle($request, function (Request $trimmed) use (&$passed) {
        return (new ConvertEmptyStringsToNull)->handle($trimmed, function (Request $converted) use (&$passed) {
            $passed = $converted;

            return new Response;
        });
    });

    if (! $passed instanceof Request) {
        throw new RuntimeException('the middleware did not pass the request on');
    }

    expect($passed->request->get('Body'))->toBe('Hello')
        ->and($passed->request->get('MediaUrl'))->toBeNull();

    $webhook = WebhookRequest::fromHttp($passed, 'twilio', null, 0);

    expect($webhook->form)->toBe(['Body' => 'Hello ', 'From' => '+1', 'MediaUrl' => '', 'Tag' => ['b', 'a']])
        ->and(TwilioSignatureValidator::signedForm($url, $webhook->form))->toBe($signed);

    (new TwilioSignatureValidator('token'))->verify($webhook);
});

it('keeps form parameter names verbatim and skips empty pairs', function () {
    expect(WebhookRequest::parseForm('a.b=1&&c%5B%5D=2&flag'))->toBe(['a.b' => '1', 'c[]' => '2', 'flag' => ''])
        ->and(WebhookRequest::parseForm(''))->toBe([]);
});
