<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Introspection\CachesEndpoint;
use Illuminate\Config\Repository;

/**
 * The store list is fed as a real Laravel `cache` config array (the shape config/cache.php ships), including
 * the credential-bearing keys a dynamodb store carries, so the "publishes name/driver/default and nothing
 * else" rule is asserted against the exact input that would leak if the endpoint dumped the store definition.
 */
function cachesEndpoint(): CachesEndpoint
{
    return new CachesEndpoint(new Repository(['cache' => [
        'default' => 'redis',
        'stores' => [
            'array' => ['driver' => 'array', 'serialize' => false],
            'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
            'dynamodb' => [
                'driver' => 'dynamodb',
                'key' => 'AKIAEXAMPLE',
                'secret' => 'wJalrXUtnFEMI-EXAMPLE-KEY',
                'table' => 'cache',
            ],
        ],
    ]]));
}

/**
 * EndpointResponse::$body is `array<mixed>|string` — narrowed by real control flow, and the null 404 signal is
 * rejected here rather than @var-ed away, so a test that expects a body cannot silently pass on a 404.
 *
 * @return array<mixed>
 */
function cachesJsonBody(?EndpointResponse $response): array
{
    if ($response === null) {
        throw new RuntimeException('Expected a non-null EndpointResponse.');
    }

    if (! is_array($response->body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $response->body;
}

it('lists every configured store with its driver and marks the default', function () {
    $body = cachesJsonBody(cachesEndpoint()->handle(new EndpointRequest('GET', [])));

    expect($body)->toBe([
        'default' => 'redis',
        'caches' => [
            'array' => ['name' => 'array', 'driver' => 'array', 'default' => false],
            'redis' => ['name' => 'redis', 'driver' => 'redis', 'default' => true],
            'dynamodb' => ['name' => 'dynamodb', 'driver' => 'dynamodb', 'default' => false],
        ],
    ]);
});

// The reason the endpoint publishes three fields and not the store definition: a dynamodb store carries an
// access key id and a secret access key, and this endpoint has no authorization story to protect them with.
it('never publishes a store credential', function () {
    $flat = json_encode(cachesJsonBody(cachesEndpoint()->handle(new EndpointRequest('GET', []))), JSON_THROW_ON_ERROR);

    expect($flat)->not->toContain('AKIAEXAMPLE')
        ->and($flat)->not->toContain('wJalrXUtnFEMI-EXAMPLE-KEY')
        ->and($flat)->not->toContain('lock_connection');
});

it('describes a single store on a sub-path', function () {
    $body = cachesJsonBody(cachesEndpoint()->handle(new EndpointRequest('GET', ['redis'])));

    expect($body)->toBe(['name' => 'redis', 'driver' => 'redis', 'default' => true]);
});

it('404s a store that is not configured', function () {
    expect(cachesEndpoint()->handle(new EndpointRequest('GET', ['memcached'])))->toBeNull();
});

it('404s a sub-path deeper than one segment', function () {
    expect(cachesEndpoint()->handle(new EndpointRequest('GET', ['redis', 'entries'])))->toBeNull();
});

// GET-only by design: eviction is destructive and firefly/actuator carries no Actuator -> Security edge, so it
// has no way to say WHO asked. POST is the only other verb the actuator route mounts, and it 404s.
it('404s a POST rather than accepting an eviction it cannot authorize', function () {
    expect(cachesEndpoint()->handle(new EndpointRequest('POST', [])))->toBeNull()
        ->and(cachesEndpoint()->handle(new EndpointRequest('POST', ['redis'])))->toBeNull();
});

// Laravel's Router answers HEAD wherever it answers GET, so getMethod() legitimately reports HEAD here; a
// naive `!== 'GET'` guard would have 404'd an ordinary probe.
it('serves a HEAD probe exactly like a GET', function () {
    expect(cachesJsonBody(cachesEndpoint()->handle(new EndpointRequest('HEAD', []))))
        ->toBe(cachesJsonBody(cachesEndpoint()->handle(new EndpointRequest('GET', []))));
});

it('reports a store whose driver is missing rather than hiding the row', function () {
    $endpoint = new CachesEndpoint(new Repository(['cache' => ['default' => 'broken', 'stores' => ['broken' => ['table' => 'cache']]]]));

    expect(cachesJsonBody($endpoint->handle(new EndpointRequest('GET', []))))
        ->toBe(['default' => 'broken', 'caches' => ['broken' => ['name' => 'broken', 'driver' => 'unknown', 'default' => true]]]);
});

// A bare skeleton (or a Lumen app that never published config/cache.php) has no cache config at all. Reporting
// a null default rather than inventing Laravel's shipped 'file' keeps the payload a description of THIS app.
it('answers a null default and an empty list when nothing is configured', function () {
    $endpoint = new CachesEndpoint(new Repository([]));

    $response = $endpoint->handle(new EndpointRequest('GET', []));

    expect($response)->toBeInstanceOf(EndpointResponse::class)
        ->and(cachesJsonBody($response))->toBe(['default' => null, 'caches' => []]);
});

it('is exposed as the caches endpoint id', function () {
    expect(cachesEndpoint()->endpointId())->toBe('caches')
        ->and(cachesEndpoint()->enabled())->toBeTrue();
});
