<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthContributorRegistry;
use Firefly\Actuator\Health\HealthEndpoint;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Actuator\Health\StatusAggregator;
use Firefly\Config\Config;
use Illuminate\Config\Repository;
use RuntimeException;

/**
 * handle() is declared `?EndpointResponse` (null signals 404 — see the unknown-group test below), so PHPStan
 * cannot statically assume non-null at any call site. Real control-flow narrowing (not a suppressing
 * `@var`/assert() override) for the tests that DO know their response is present.
 */
function assumeResponse(?EndpointResponse $response): EndpointResponse
{
    if ($response === null) {
        throw new RuntimeException('Expected a non-null EndpointResponse.');
    }

    return $response;
}

/**
 * EndpointResponse::$body is `array<mixed>|string` (text endpoints use string) — narrow to array before
 * indexing a key, for the same real-control-flow reason as assumeResponse() above.
 *
 * @return array<mixed>
 */
function jsonBody(EndpointResponse $response): array
{
    if (! is_array($response->body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $response->body;
}

function upIndicator(): HealthIndicator
{
    return new class implements HealthIndicator
    {
        public function health(): Health
        {
            return Health::up(['free' => 100]);
        }
    };
}

function throwingIndicator(): HealthIndicator
{
    return new class implements HealthIndicator
    {
        public function health(): Health
        {
            throw new RuntimeException('boom');
        }
    };
}

/**
 * @param  array<string, mixed>  $management
 */
function healthEndpoint(HealthContributorRegistry $registry, array $management = []): HealthEndpoint
{
    return new HealthEndpoint($registry, new StatusAggregator, new Config(new Repository(['firefly' => ['management' => $management]])));
}

it('aggregates UP and answers 200 with no details by default', function () {
    $registry = new HealthContributorRegistry;
    $registry->register('ping', upIndicator());

    $response = assumeResponse(healthEndpoint($registry)->handle(new EndpointRequest('GET', [])));

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe(['status' => 'UP']);
});

it('shows components with details when show-details=always', function () {
    $registry = new HealthContributorRegistry;
    $registry->register('ping', upIndicator());

    $response = assumeResponse(
        healthEndpoint($registry, ['endpoint' => ['health' => ['show-details' => 'always']]])
            ->handle(new EndpointRequest('GET', []))
    );

    expect($response->body)->toBe([
        'status' => 'UP',
        'components' => ['ping' => ['status' => 'UP', 'details' => ['free' => 100]]],
    ]);
});

it('renders a throwing indicator as DOWN and answers 503 — never a 500', function () {
    $registry = new HealthContributorRegistry;
    $registry->register('db', throwingIndicator());

    $response = assumeResponse(healthEndpoint($registry)->handle(new EndpointRequest('GET', [])));

    expect($response->status)->toBe(503)
        ->and(jsonBody($response)['status'])->toBe('DOWN');
});

it('aggregates only a group on /health/{group} and 404s an unknown group', function () {
    $registry = new HealthContributorRegistry;
    $registry->register('ping', upIndicator());
    $registry->register('db', throwingIndicator());

    $endpoint = healthEndpoint($registry, ['endpoint' => ['health' => ['group' => ['readiness' => ['include' => 'ping']]]]]);

    $group = assumeResponse($endpoint->handle(new EndpointRequest('GET', ['readiness'])));
    $unknown = $endpoint->handle(new EndpointRequest('GET', ['nope']));

    expect($group->status)->toBe(200)
        ->and(jsonBody($group)['status'])->toBe('UP')
        ->and($unknown)->toBeNull();
});
