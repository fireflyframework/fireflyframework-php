<?php

declare(strict_types=1);

use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\Database\Outbox\RelayDownstream;
use Firefly\Eda\Database\Tests\Fixtures\RecordingDownstream;
use Firefly\Eda\Database\Tests\Support\DatabaseRelayCommandTestCase;
use Illuminate\Support\Facades\DB;

uses(DatabaseRelayCommandTestCase::class);

function relayCommandRow(int $id): void
{
    DB::table(OutboxSchema::TABLE)->insert([
        'destination' => 'orders', 'channel' => 'firefly_eda_events', 'event_type' => 'order.created',
        'payload' => json_encode(['id' => $id], JSON_THROW_ON_ERROR), 'headers' => '{}',
        'status' => OutboxSchema::STATUS_PENDING, 'attempts' => 0,
    ]);
}

it('relays committed rows to the bound downstream under provider=database', function () {
    /** @var DatabaseRelayCommandTestCase $this */
    $downstream = new RecordingDownstream;
    $this->app()->instance(RelayDownstream::BINDING, $downstream);
    relayCommandRow(3);

    expect($this->runRelay())->toBe(0)
        ->and($downstream->published)->toHaveCount(1)
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PUBLISHED);
});

it('fails before claiming anything when a forced claim strategy is unsupported', function () {
    /** @var DatabaseRelayCommandTestCase $this */
    $this->app()->instance(RelayDownstream::BINDING, new RecordingDownstream);
    config(['firefly.eda.database.claim-strategy' => 'skip-locked']);
    relayCommandRow(4);

    expect($this->runRelay())->toBe(1)
        ->and($this->relayOutput())->toContain('sqlite')
        ->and(DB::table(OutboxSchema::TABLE)->whereNull('claimed_by')->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(1);
});
