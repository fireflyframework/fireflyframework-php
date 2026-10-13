<?php

declare(strict_types=1);

use Firefly\Webhooks\Tests\Support\WebhooksCapstoneTestCase;

uses(WebhooksCapstoneTestCase::class);

it('accepts a signed webhook with 202 and publishes it to the event bus', function () {
    /** @var WebhooksCapstoneTestCase $this */
    $body = '{"type":"order.created","id":7}';

    $this->postWebhook('/webhooks/acme', $body, ['X-Signature' => 'sha256='.hash_hmac('sha256', $body, 'acme-secret'), 'X-Idempotency-Key' => 'del-1'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'accepted')
        ->assertJsonPath('eventType', 'webhook.acme.order.created');

    expect($this->events->published)->toHaveCount(1)
        ->and($this->events->published[0]['destination'])->toBe('webhooks.acme')
        ->and($this->events->published[0]['eventType'])->toBe('webhook.acme.order.created')
        ->and($this->events->published[0]['payload']['body'])->toBe(['type' => 'order.created', 'id' => 7])
        ->and($this->events->published[0]['payload']['idempotencyKey'])->toBe('del-1');
});

it('answers a redelivery 200 duplicate and publishes it once', function () {
    /** @var WebhooksCapstoneTestCase $this */
    $body = '{"type":"order.created","id":7}';
    $headers = ['X-Signature' => 'sha256='.hash_hmac('sha256', $body, 'acme-secret'), 'X-Idempotency-Key' => 'del-2'];

    $this->postWebhook('/webhooks/acme', $body, $headers)->assertStatus(202);
    $this->postWebhook('/webhooks/acme', $body, $headers)->assertStatus(200)->assertJsonPath('status', 'duplicate');

    expect($this->events->published)->toHaveCount(1);
});

it('refuses with problem+json and a stable code', function (string $uri, string $body, array $headers, int $status, string $code) {
    /** @var WebhooksCapstoneTestCase $this */
    /** @var array<string, string> $headers */
    $response = $this->postWebhook($uri, $body, $headers)->assertStatus($status)->assertJsonPath('code', $code);

    expect((string) $response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($this->events->published)->toBe([]);
})->with([
    'bad signature' => ['/webhooks/acme', '{"type":"x"}', ['X-Signature' => 'sha256=00'], 401, 'WEBHOOK_SIGNATURE_INVALID'],
    'unsigned' => ['/webhooks/acme', '{"type":"x"}', [], 401, 'WEBHOOK_SIGNATURE_INVALID'],
    'unknown provider' => ['/webhooks/nobody', '{}', [], 404, 'WEBHOOK_PROVIDER_NOT_FOUND'],
    'too large' => ['/webhooks/open', '{"pad":"'.str_repeat('x', 5000).'"}', [], 413, 'WEBHOOK_PAYLOAD_TOO_LARGE'],
    'malformed JSON' => ['/webhooks/open', '{"type":', [], 400, 'WEBHOOK_PAYLOAD_INVALID'],
]);

it('names the provider in the problem document', function () {
    /** @var WebhooksCapstoneTestCase $this */
    $this->postWebhook('/webhooks/acme', '{}', ['X-Signature' => 'sha256=00'])->assertStatus(401)->assertJsonPath('provider', 'acme');
});

it('verifies Stripe, deduplicates on the event id, and refuses a stale timestamp', function () {
    /** @var WebhooksCapstoneTestCase $this */
    $body = '{"id":"evt_1","type":"payment_intent.succeeded"}';
    $sign = static fn (int $t): string => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, 'whsec_test');

    $this->postWebhook('/webhooks/stripe', $body, ['Stripe-Signature' => $sign(time())])
        ->assertStatus(202)->assertJsonPath('eventType', 'webhook.stripe.payment_intent.succeeded');
    $this->postWebhook('/webhooks/stripe', $body, ['Stripe-Signature' => $sign(time())])
        ->assertStatus(200)->assertJsonPath('idempotencyKey', 'evt_1');
    $this->postWebhook('/webhooks/stripe', $body, ['Stripe-Signature' => $sign(time() - 3600)])
        ->assertStatus(401)->assertJsonPath('code', 'WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE');
});

it('verifies GitHub and takes the event type from X-GitHub-Event', function () {
    /** @var WebhooksCapstoneTestCase $this */
    $body = '{"ref":"refs/heads/main"}';

    $this->postWebhook('/webhooks/github', $body, [
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'gh-secret'),
        'X-GitHub-Event' => 'push',
        'X-GitHub-Delivery' => 'gh-delivery-1',
    ])->assertStatus(202)->assertJsonPath('eventType', 'webhook.github.push')->assertJsonPath('idempotencyKey', 'gh-delivery-1');
});

it('verifies a Twilio form callback against the URL it was sent to', function () {
    /** @var WebhooksCapstoneTestCase $this */
    $form = ['MessageSid' => 'SM1', 'MessageStatus' => 'delivered', 'To' => '+15005550006'];
    $url = 'http://localhost/webhooks/twilio';
    $signature = base64_encode(hash_hmac('sha1', $url.'MessageSidSM1MessageStatusdeliveredTo+15005550006', 'twilio-token', true));

    $this->postWebhook('/webhooks/twilio', http_build_query($form), ['X-Twilio-Signature' => $signature], $form, 'application/x-www-form-urlencoded')
        ->assertStatus(202)->assertJsonPath('eventType', 'webhook.twilio.delivered');

    expect($this->events->published[0]['payload']['body'])->toBe($form);
});

it('accepts an unsigned delivery for a provider explicitly set to none', function () {
    /** @var WebhooksCapstoneTestCase $this */
    $this->postWebhook('/webhooks/open', '{"type":"ping"}')->assertStatus(202)->assertJsonPath('eventType', 'webhook.open.ping');
});
