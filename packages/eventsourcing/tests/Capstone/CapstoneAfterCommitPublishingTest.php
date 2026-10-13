<?php

declare(strict_types=1);

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\EventSourcing\Publishing\AfterCommitEventStorePublisher;
use Firefly\EventSourcing\Publishing\EventStorePublisher;
use Firefly\EventSourcing\Tests\CapstoneFixtures\OrderRepository;
use Firefly\EventSourcing\Tests\Fixtures\Order;
use Firefly\EventSourcing\Tests\Support\AfterCommitPublishingCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(AfterCommitPublishingCapstoneTestCase::class);

it('delivers each event once after commit and nothing for a rolled-back save', function (): void {
    /** @var AfterCommitPublishingCapstoneTestCase $this */
    $seen = [];
    $this->app()->make(EventPublisher::class)->subscribe('order.*', static function (EventEnvelope $envelope) use (&$seen): void {
        $seen[] = ($envelope->headers['x-aggregate-id'] ?? '?').':'.$envelope->eventType;
    });
    $orders = $this->app()->make(OrderRepository::class);

    $insideCommit = null;
    DB::transaction(static function () use ($orders, &$seen, &$insideCommit): void {
        $orders->save(Order::place('pub-3', 1.0));
        $insideCommit = count($seen);
    });
    try {
        DB::transaction(static function () use ($orders): void {
            $orders->save(Order::place('pub-4', 1.0));
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($this->app()->make(EventStorePublisher::class))->toBeInstanceOf(AfterCommitEventStorePublisher::class)
        ->and($insideCommit)->toBe(0)
        ->and($seen)->toBe(['pub-3:order.placed']);
});
