<?php

declare(strict_types=1);

use Firefly\Observability\HttpExchanges\HttpExchange;

it('renders the exact flat row a dashboard reads, omitting requestHeaders when there are none', function () {
    $exchange = new HttpExchange('2026-09-03T10:11:12.131415Z', 'GET', '/users/{id}', 200, 12.345, 'corr-1');

    expect($exchange->toArray())->toBe([
        'timestamp' => '2026-09-03T10:11:12.131415Z',
        'method' => 'GET',
        'uri' => '/users/{id}',
        'status' => 200,
        'durationMs' => 12.345,
        'correlationId' => 'corr-1',
    ]);
});

/**
 * The key is present ONLY when there is something in it. json_encode renders an empty PHP array as `[]`, not
 * `{}`, so emitting the key unconditionally would hand a client an array on exactly the requests that had no
 * headers and an object on the rest — the same hazard ActuatorDispatchAction::toResponse() documents for the
 * top-level body.
 */
it('includes requestHeaders only when headers were captured', function () {
    $exchange = new HttpExchange('2026-09-03T10:11:12.131415Z', 'GET', '/x', 200, 1.0, null, ['accept' => 'application/json']);

    expect($exchange->toArray())->toBe([
        'timestamp' => '2026-09-03T10:11:12.131415Z',
        'method' => 'GET',
        'uri' => '/x',
        'status' => 200,
        'durationMs' => 1.0,
        'correlationId' => null,
        'requestHeaders' => ['accept' => 'application/json'],
    ]);
});

/**
 * The `@`-epoch DateTimeImmutable constructor truncates to whole seconds, which would collapse every row of a
 * traffic burst onto the same timestamp and make the newest-first ordering look arbitrary. This pins the
 * microseconds surviving.
 */
it('formats a microtime float as ISO-8601 UTC without losing the microseconds', function () {
    // 2021-01-01T00:00:00Z is 1609459200; the .654321 must survive.
    expect(HttpExchange::timestampFrom(1609459200.654321))->toBe('2021-01-01T00:00:00.654321Z');
});

it('round-trips through the array form the cache-backed recorder stores', function () {
    $original = new HttpExchange('2026-09-03T10:11:12.131415Z', 'POST', '/orders', 201, 42.5, 'corr-9', ['accept' => '*/*']);

    $restored = HttpExchange::fromArray($original->toArray());

    expect($restored)->not->toBeNull()
        ->and($restored?->toArray())->toBe($original->toArray());
});

/**
 * A shared cache store is not a private data structure — a key collision, another application on the same Redis,
 * or a rolling deploy mid-schema-change can all put something else under these keys. A dashboard panel must lose
 * one row, not answer 500 for the whole endpoint.
 */
it('returns null rather than throwing for a row that is not shaped like an exchange', function () {
    expect(HttpExchange::fromArray([]))->toBeNull()
        ->and(HttpExchange::fromArray(['timestamp' => 1, 'method' => 'GET', 'uri' => '/x', 'status' => 200, 'durationMs' => 1.0]))->toBeNull()
        ->and(HttpExchange::fromArray(['timestamp' => 't', 'method' => 'GET', 'uri' => '/x', 'status' => '200', 'durationMs' => 1.0]))->toBeNull();
});

/**
 * A cache driver that round-trips through JSON rather than PHP serialize() writes 12.0 and reads back the
 * integer 12. Rejecting that row would silently drop every exchange that happened to land on a whole
 * millisecond.
 */
it('accepts an integer durationMs from a JSON-serialising cache driver and normalises it to float', function () {
    $restored = HttpExchange::fromArray([
        'timestamp' => '2026-09-03T10:11:12.131415Z',
        'method' => 'GET',
        'uri' => '/x',
        'status' => 200,
        'durationMs' => 12,
        'correlationId' => null,
    ]);

    expect($restored?->durationMs)->toBe(12.0);
});
