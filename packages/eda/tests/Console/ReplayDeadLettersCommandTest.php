<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Eda\DeadLetter\DeadLetterSchema;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tests\DeadLetterFixtures\FlakyOrderListener;
use Firefly\Eda\Tests\Support\DeadLetterCapstoneTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Support\Facades\DB;

uses(DeadLetterCapstoneTestCase::class);

it('keeps an exhausted envelope durably and replays it once the listener is fixed', function () {
    /** @var DeadLetterCapstoneTestCase $this */
    $app = $this->app();
    $app->make(EventPublisher::class)->publish('orders', 'order.placed', ['id' => 1]);
    $app->make(EventPublisher::class)->publish('orders', 'order.paid', ['id' => 1]);

    expect($app->make(DeadLetterStore::class))->toBeInstanceOf(DatabaseDeadLetterStore::class)
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(2);

    // Dry run: lists, changes nothing.
    expect($this->replay(['--dry-run' => true]))->toBe(0)
        ->and($this->replayOutput())->toContain('would replay')
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(2);

    // The outage is over: replay only order.placed, then the rest.
    FlakyOrderListener::$failing = false;
    expect($this->replay(['--event-type' => 'order.placed']))->toBe(0)
        ->and(DB::table(DeadLetterSchema::TABLE)->pluck('event_type')->all())->toBe(['order.paid'])
        ->and($this->replay())->toBe(0)
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(0);

    /** @var ListenerSpy $spy */
    $spy = $app->make(ListenerSpy::class);
    expect($spy->seen)->toHaveCount(2)
        ->and($spy->seen[0])->toStartWith('order.placed#')
        ->and($spy->seen[0])->not->toEndWith('#first');
});

it('dead-letters a replay that fails again, and --keep leaves the original in place', function () {
    /** @var DeadLetterCapstoneTestCase $this */
    $this->app()->make(EventPublisher::class)->publish('orders', 'order.placed', ['id' => 2]);

    // Still failing: the republished envelope is dead-lettered again by the memory bus (the publish itself succeeds),
    // and --keep leaves the original entry in place — two entries, nothing lost.
    expect($this->replay(['--keep' => true]))->toBe(0)
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(2);
});

it('keeps an entry whose republish throws, and exits 1', function () {
    /** @var DeadLetterCapstoneTestCase $this */
    $this->app()->make(EventPublisher::class)->publish('orders', 'order.placed', ['id' => 3]);
    $this->app()->instance(EventPublisher::class, new class implements EventPublisher
    {
        public function subscribe(string $eventTypePattern, callable $handler): void {}

        public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
        {
            throw new RuntimeException('broker down');
        }

        public function start(): void {}

        public function stop(): void {}
    });

    expect($this->replay())->toBe(1)
        ->and($this->replayOutput())->toContain('could not replay')
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(1);
});

it('refuses a bound DeadLetterStore that cannot select or remove entries, and exits 1', function () {
    /** @var DeadLetterCapstoneTestCase $this */
    $this->app()->instance(DeadLetterStore::class, new class implements DeadLetterStore
    {
        public function store(EventEnvelope $envelope, Throwable $cause): void {}

        public function all(): array
        {
            return [];
        }
    });

    expect($this->replay())->toBe(1)
        ->and($this->replayOutput())->toContain('cannot select or remove entries');
});
