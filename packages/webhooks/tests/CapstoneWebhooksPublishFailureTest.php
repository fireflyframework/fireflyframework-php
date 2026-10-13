<?php

declare(strict_types=1);

use Firefly\Webhooks\Tests\Support\FailingPublisherWebhooksCapstoneTestCase;

uses(FailingPublisherWebhooksCapstoneTestCase::class);

it('answers 503 and keeps the delivery retryable when the bus refuses the publish', function () {
    /** @var FailingPublisherWebhooksCapstoneTestCase $this */
    $body = '{"type":"order.created"}';
    $headers = ['X-Signature' => 'sha256='.hash_hmac('sha256', $body, 'acme-secret'), 'X-Idempotency-Key' => 'del-503'];

    $this->postWebhook('/webhooks/acme', $body, $headers)->assertStatus(503)->assertJsonPath('code', 'WEBHOOK_PUBLISH_FAILED');
    // Not "duplicate": the failed publish released its claim.
    $this->postWebhook('/webhooks/acme', $body, $headers)->assertStatus(503)->assertJsonPath('code', 'WEBHOOK_PUBLISH_FAILED');
});
