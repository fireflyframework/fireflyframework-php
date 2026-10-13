<?php

declare(strict_types=1);

use Firefly\Webhooks\Idempotency\DatabaseWebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookIdempotencyStore;
use Firefly\Webhooks\Idempotency\WebhookSchema;
use Firefly\Webhooks\Tests\Support\WebhooksDatabaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(WebhooksDatabaseTestCase::class);

it('binds the database store and loads its migration when idempotency.store=database', function () {
    /** @var WebhooksDatabaseTestCase $this */
    expect(Artisan::call('migrate'))->toBe(0);

    expect($this->app()->make(WebhookIdempotencyStore::class))->toBeInstanceOf(DatabaseWebhookIdempotencyStore::class)
        ->and(Schema::hasTable(WebhookSchema::TABLE))->toBeTrue();
});

it('prunes expired receipts with php artisan firefly:webhooks:prune', function () {
    /** @var WebhooksDatabaseTestCase $this */
    expect(Artisan::call('migrate'))->toBe(0);
    DB::table(WebhookSchema::TABLE)->insert([
        ['provider' => 'acme', 'idempotency_key' => 'old', 'event_id' => 'e-1', 'claimed_at' => 1, 'expires_at' => 2],
        ['provider' => 'acme', 'idempotency_key' => 'live', 'event_id' => 'e-2', 'claimed_at' => time(), 'expires_at' => time() + 3600],
    ]);

    expect(Artisan::call('firefly:webhooks:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Pruned 1 expired webhook receipt');

    expect(DB::table(WebhookSchema::TABLE)->pluck('idempotency_key')->all())->toBe(['live']);
});
