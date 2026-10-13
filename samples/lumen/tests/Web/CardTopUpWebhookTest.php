<?php

declare(strict_types=1);

namespace Lumen\Tests\Web;

use Firefly\Eda\DeadLetter\DeadLetterStore;

uses(LumenWebhooksTestCase::class);

/** A card-processor delivery: the JSON body the processor POSTs, byte for byte. */
function lumenTopUp(string $walletId, int $amountMinor, string $eventId): string
{
    return json_encode([
        'id' => $eventId,
        'type' => 'top_up.succeeded',
        'data' => ['wallet_id' => $walletId, 'amount_minor' => $amountMinor],
    ], JSON_THROW_ON_ERROR);
}

/**
 * The two headers the processor sends with a delivery: the hmac validator's default signature over the exact bytes,
 * and the delivery id that makes a redelivery recognisable.
 *
 * @return array<string, string>
 */
function lumenTopUpHeaders(string $body, string $eventId): array
{
    return [
        'X-Signature' => 'sha256='.hash_hmac('sha256', $body, LumenWebhooksTestCase::SECRET),
        'X-Idempotency-Key' => $eventId,
    ];
}

it('deposits a signed card top-up once, however often the processor delivers it', function () {
    /** @var LumenWebhooksTestCase $this */
    /** @var string $id */
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-card', 'currency' => 'EUR'])->json('wallet_id');
    $body = lumenTopUp($id, 2500, 'evt_1');

    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders($body, 'evt_1'))
        ->assertStatus(202)
        ->assertJson(['status' => 'accepted', 'provider' => 'card-processor', 'eventType' => 'webhook.card-processor.top_up.succeeded']);

    // The processor never saw the 202 and delivers the same event again: answered, not deposited twice.
    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders($body, 'evt_1'))
        ->assertStatus(200)
        ->assertJson(['status' => 'duplicate']);

    $this->getJson("/api/v1/wallets/{$id}/balance")->assertOk()->assertJson(['balance_minor' => 2500]);
})->group('lumen');

it('refuses a delivery whose signature does not cover the body it came with', function () {
    /** @var LumenWebhooksTestCase $this */
    /** @var string $id */
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-forged', 'currency' => 'EUR'])->json('wallet_id');
    $body = lumenTopUp($id, 2500, 'evt_2');

    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders(lumenTopUp($id, 990000, 'evt_2'), 'evt_2'))
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'WEBHOOK_SIGNATURE_INVALID');

    $this->getJson("/api/v1/wallets/{$id}/balance")->assertOk()->assertJson(['balance_minor' => 0]);
})->group('lumen');

it('dead-letters a verified top-up for an unknown wallet instead of failing the delivery', function () {
    /** @var LumenWebhooksTestCase $this */
    $body = lumenTopUp('no-such-wallet', 2500, 'evt_3');

    $this->postWebhook('/webhooks/card-processor', $body, lumenTopUpHeaders($body, 'evt_3'))->assertStatus(202);

    $entries = $this->app()->make(DeadLetterStore::class)->all();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->envelope->eventType)->toBe('webhook.card-processor.top_up.succeeded')
        ->and($entries[0]->exceptionMessage)->toContain('no-such-wallet');
})->group('lumen');
