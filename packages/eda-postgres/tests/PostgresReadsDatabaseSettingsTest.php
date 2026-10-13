<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Database\DatabaseEventConsumer;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\PostgresEventConsumer;
use Firefly\Eda\Postgres\Tests\Support\PostgresSettingsCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(PostgresSettingsCapstoneTestCase::class);

it('applies firefly.eda.database.* under provider=postgres', function () {
    /** @var PostgresSettingsCapstoneTestCase $this */
    $app = $this->app();
    $app->make(EventPublisher::class)->publish('users', 'user.created', ['id' => 1]);

    /** @var EventConsumer $consumer */
    $consumer = $app->make(EventConsumer::class);
    (new ConsumerLoop)->run($consumer, function (): void {
        throw new RuntimeException('handler boom');
    }, new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10));

    $row = DB::table(OutboxSchema::TABLE)->first();

    // max-attempts=1: the first failure is final; the row carries the database channel.
    expect($consumer)->toBeInstanceOf(PostgresEventConsumer::class)
        ->and($consumer)->toBeInstanceOf(DatabaseEventConsumer::class)
        ->and($row?->status)->toBe(OutboxSchema::STATUS_FAILED)
        ->and($row?->channel)->toBe('orders_channel');
});
