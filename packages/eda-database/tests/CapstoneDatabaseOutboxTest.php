<?php

declare(strict_types=1);

use Firefly\Actuator\Health\Status;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Database\DatabaseEventConsumer;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\OutboxPreCommitHook;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\Database\OutboxHealthIndicator;
use Firefly\Eda\Database\Tests\Fixtures\OrderPlaced;
use Firefly\Eda\Database\Tests\Support\DatabaseOutboxCapstoneTestCase;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Support\Facades\DB;

uses(DatabaseOutboxCapstoneTestCase::class);

it('binds the database writer and consumer, and drives the app #[EventListener] from a committed row', function () {
    /** @var DatabaseOutboxCapstoneTestCase $this */
    $app = $this->app();

    DB::transaction(fn () => $app->make(EventPublisher::class)->publish('orders', 'order.placed', ['id' => 7]));

    /** @var SubscriberRegistry $registry */
    $registry = $app->make(SubscriberRegistry::class);
    /** @var EventConsumer $consumer */
    $consumer = $app->make(EventConsumer::class);
    $processed = (new ConsumerLoop)->run($consumer, fn (EventEnvelope $envelope) => $registry->deliver($envelope), new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10));

    /** @var ListenerSpy $spy */
    $spy = $app->make(ListenerSpy::class);

    expect($app->make(EventPublisher::class))->toBeInstanceOf(DatabaseEventPublisher::class)
        ->and($consumer)->toBeInstanceOf(DatabaseEventConsumer::class)
        ->and($processed)->toBe(1)
        ->and($spy->seen)->toBe(['order.placed'])
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PUBLISHED);
});

it('writes a domain event in the aggregate\'s transaction through the bound pre-commit hook, and drops it on rollback', function () {
    /** @var DatabaseOutboxCapstoneTestCase $this */
    /** @var OutboxPreCommitHook $hook */
    $hook = $this->app()->make(OutboxPreCommitHook::class);

    DB::transaction(fn () => $hook->handle(new OrderPlaced(41), null));
    try {
        DB::transaction(function () use ($hook): void {
            $hook->handle(new OrderPlaced(42), null);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table(OutboxSchema::TABLE)->where('event_type', 'OrderPlaced')->count())->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)->value('destination'))->toBe('cqrs.events');
});

it('reports the outbox UP with the driver and the claim it will use', function () {
    /** @var DatabaseOutboxCapstoneTestCase $this */
    $indicator = $this->app()->make(OutboxHealthIndicator::class);

    $health = $indicator->health();

    expect($health->status)->toBe(Status::Up)
        ->and($health->details)->toBe(['broker' => 'database', 'driver' => 'sqlite', 'claim-strategy' => 'optimistic-lease', 'pending' => 0]);
});

it('reports the outbox DOWN with the error when the forced claim strategy is one this server cannot honour', function () {
    /** @var DatabaseOutboxCapstoneTestCase $this */
    config()->set('firefly.eda.database.claim-strategy', 'skip-locked');
    $indicator = $this->app()->make(OutboxHealthIndicator::class);

    $health = $indicator->health();

    expect($health->status)->toBe(Status::Down)
        ->and($health->details['broker'] ?? null)->toBe('database')
        ->and($health->details['error'] ?? '')->toContain('claim-strategy="skip-locked"');
});
