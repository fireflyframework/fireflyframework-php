<?php

declare(strict_types=1);

use Firefly\Observability\Metrics\MeterType;
use Firefly\Observability\Metrics\SimpleMeterRegistry;

it('registers counters idempotently by name + sorted tags', function () {
    $registry = new SimpleMeterRegistry;

    $a = $registry->counter('http_requests', ['method' => 'GET', 'status' => '200']);
    $b = $registry->counter('http_requests', ['status' => '200', 'method' => 'GET']); // same set, different order
    $a->increment();
    $b->increment(2.0);

    expect($a)->toBe($b)->and($a->count())->toBe(3.0)
        ->and($registry->meters())->toHaveCount(1);
});

it('records timers as count + total seconds', function () {
    $registry = new SimpleMeterRegistry;
    $timer = $registry->timer('cqrs_commands', ['type' => 'OpenAccount']);
    $timer->record(0.5);
    $timer->record(1.5);

    expect($timer->count())->toBe(2)->and($timer->totalTimeSeconds())->toBe(2.0)
        ->and($timer->type())->toBe(MeterType::Timer);
});

it('samples supplier-backed gauges at read time', function () {
    $registry = new SimpleMeterRegistry;
    $value = 41;
    $gauge = $registry->gauge('queue_depth', [], function () use (&$value): float {
        return (float) $value;
    });
    $value = 42;

    expect($gauge->value())->toBe(42.0)->and($gauge->type())->toBe(MeterType::Gauge);
});

it('exposes the recorder facade delegating to meters', function () {
    $registry = new SimpleMeterRegistry;
    $registry->increment('errors', ['kind' => 'io'], 5.0);
    $registry->record('latency', ['op' => 'read'], 0.25);
    $registry->setGauge('temp', [], 3.0);

    $names = array_map(fn ($m) => $m->name(), $registry->meters());
    expect($names)->toContain('errors')->toContain('latency')->toContain('temp')
        ->and($registry->counter('errors', ['kind' => 'io'])->count())->toBe(5.0);
});

it('rejects registering the same metric name under a different type (FIX 3)', function () {
    $registry = new SimpleMeterRegistry;
    $registry->counter('foo');

    expect(fn () => $registry->gauge('foo', [], fn (): float => 1.0))
        ->toThrow(InvalidArgumentException::class, "Metric 'foo' already registered as counter; cannot re-register as gauge.");
});

it('rejects a type conflict via the recorder facade too (setGauge vs counter)', function () {
    $registry = new SimpleMeterRegistry;
    $registry->timer('bar');

    expect(fn () => $registry->setGauge('bar', [], 1.0))
        ->toThrow(InvalidArgumentException::class, "Metric 'bar' already registered as timer; cannot re-register as gauge.");
});

it('still allows idempotent same-name-same-type registration after the type guard (existing behaviour preserved)', function () {
    $registry = new SimpleMeterRegistry;

    $a = $registry->counter('foo');
    $b = $registry->counter('foo');
    $a->increment();
    $b->increment(2.0);

    expect($a)->toBe($b)->and($a->count())->toBe(3.0)
        ->and($registry->meters())->toHaveCount(1);
});
