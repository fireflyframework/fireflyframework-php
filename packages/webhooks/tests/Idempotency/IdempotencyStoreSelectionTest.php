<?php

declare(strict_types=1);

use Firefly\Webhooks\Idempotency\CacheWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookSchema;
use Firefly\Webhooks\Tests\Support\WebhooksCacheStoreTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(WebhooksCacheStoreTestCase::class);

it('binds the cache store by default and gives the application no receipts table', function () {
    /** @var WebhooksCacheStoreTestCase $this */
    expect(Artisan::call('migrate'))->toBe(0);

    $store = $this->app()->make(WebhookIdempotencyStore::class);

    expect($store)->toBeInstanceOf(CacheWebhookIdempotencyStore::class)
        ->and(Schema::hasTable(WebhookSchema::TABLE))->toBeFalse()
        ->and($store->claim('acme', 'evt_1', 'e-1', 60))->toBeTrue()
        ->and($store->claim('acme', 'evt_1', 'e-2', 60))->toBeFalse();
});

it('answers firefly:webhooks:prune without touching anything on the cache store', function () {
    /** @var WebhooksCacheStoreTestCase $this */
    expect(Artisan::call('firefly:webhooks:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('not the database store');
});
