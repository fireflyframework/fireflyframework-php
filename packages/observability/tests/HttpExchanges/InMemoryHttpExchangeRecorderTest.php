<?php

declare(strict_types=1);

use Firefly\Observability\HttpExchanges\HttpExchange;
use Firefly\Observability\HttpExchanges\HttpExchangeCapacity;
use Firefly\Observability\HttpExchanges\InMemoryHttpExchangeRecorder;

/** Named distinctly so the file has no top-level-function collision when Pest loads the whole suite. */
function inMemoryExchange(string $uri, int $status = 200): HttpExchange
{
    return new HttpExchange('2026-09-03T10:11:12.131415Z', 'GET', $uri, $status, 1.0, null);
}

it('returns exchanges newest first', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);

    $recorder->record(inMemoryExchange('/first'));
    $recorder->record(inMemoryExchange('/second'));
    $recorder->record(inMemoryExchange('/third'));

    expect(array_map(static fn (HttpExchange $e): string => $e->uri, $recorder->exchanges()))
        ->toBe(['/third', '/second', '/first']);
});

it('evicts the oldest exchange once the ring is full and keeps the monotonic total', function () {
    $recorder = new InMemoryHttpExchangeRecorder(2);

    $recorder->record(inMemoryExchange('/a'));
    $recorder->record(inMemoryExchange('/b'));
    $recorder->record(inMemoryExchange('/c'));

    expect(array_map(static fn (HttpExchange $e): string => $e->uri, $recorder->exchanges()))->toBe(['/c', '/b'])
        // recorded() is NOT capped at capacity: recorded() - count(exchanges()) is how many rows have been
        // evicted, which is what lets a dashboard say "showing the last 2 of 3".
        ->and($recorder->recorded())->toBe(3)
        ->and($recorder->capacity())->toBe(2);
});

/**
 * capacity=0 is a plausible way for someone to try to switch recording off through the wrong key. Left
 * unclamped it makes CacheHttpExchangeRecorder compute `$seq % 0` — a DivisionByZeroError thrown out of a web
 * filter, i.e. a config typo that 500s every request in the application. Both recorders clamp through the same
 * helper so a configured capacity cannot mean two different things depending on which store is wired.
 */
it('clamps a nonsensical capacity instead of degenerating', function () {
    expect((new InMemoryHttpExchangeRecorder(0))->capacity())->toBe(HttpExchangeCapacity::MIN)
        ->and((new InMemoryHttpExchangeRecorder(-5))->capacity())->toBe(HttpExchangeCapacity::MIN)
        ->and((new InMemoryHttpExchangeRecorder(1_000_000))->capacity())->toBe(HttpExchangeCapacity::MAX);
});

/**
 * The honesty contract: the endpoint prints these two so an operator staring at an empty panel under PHP-FPM
 * learns WHY it is empty instead of concluding their application is serving no traffic.
 */
it('declares itself process-local so the endpoint can explain an empty buffer', function () {
    $recorder = new InMemoryHttpExchangeRecorder;

    expect($recorder->storage())->toBe('memory')
        ->and($recorder->processLocal())->toBeTrue()
        ->and($recorder->capacity())->toBe(HttpExchangeCapacity::DEFAULT);
});
