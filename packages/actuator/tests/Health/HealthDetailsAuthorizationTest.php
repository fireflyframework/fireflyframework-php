<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Health\DenyHealthDetailsAuthorizer;
use Firefly\Actuator\Health\Health;
use Firefly\Actuator\Health\HealthContributorRegistry;
use Firefly\Actuator\Health\HealthDetailsAuthorizer;
use Firefly\Actuator\Health\HealthEndpoint;
use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Actuator\Health\StatusAggregator;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

/**
 * `when-authorized` is the value that used to be a documented lie: actuator had no way to ask who was
 * calling, so it degraded to `never`. These four cases pin the new contract — `always` and `never` decide
 * on their own and never consult anybody, `when-authorized` asks the HealthDetailsAuthorizer port and
 * nothing else, and withholding the details never withholds the aggregate status (a probe must still be
 * able to read UP/DOWN from an unauthenticated scrape).
 *
 * The helpers are prefixed `hda*`: Pest evaluates every test file in ONE process, so a bare
 * `healthEndpoint()` here would redeclare HealthEndpointTest's and abort the suite with a fatal.
 */
function hdaEndpoint(string $showDetails, HealthDetailsAuthorizer $authorizer): HealthEndpoint
{
    $registry = new HealthContributorRegistry;
    $registry->register('demo', new class implements HealthIndicator
    {
        public function health(): Health
        {
            return Health::up(['version' => '1.2.3']);
        }
    });

    $config = new Config(new Repository(['firefly' => ['management' => ['endpoint' => ['health' => ['show-details' => $showDetails]]]]]));

    return new HealthEndpoint($registry, new StatusAggregator, $config, $authorizer);
}

/**
 * handle() is `?EndpointResponse` (null = 404 on an unknown group) and its body is `array<mixed>|string`,
 * so narrow both by real control flow rather than by a suppressing annotation.
 *
 * @return array<mixed>
 */
function hdaBody(string $showDetails, HealthDetailsAuthorizer $authorizer): array
{
    $response = hdaEndpoint($showDetails, $authorizer)->handle(new EndpointRequest('GET', []));

    if (! $response instanceof EndpointResponse || ! is_array($response->body)) {
        throw new RuntimeException('Expected a JSON (array) EndpointResponse body.');
    }

    return $response->body;
}

function hdaAllowAll(): HealthDetailsAuthorizer
{
    return new class implements HealthDetailsAuthorizer
    {
        public function mayReadDetails(): bool
        {
            return true;
        }
    };
}

it('emits components under show-details: always, whatever the authorizer says', function () {
    expect(hdaBody('always', new DenyHealthDetailsAuthorizer))->toHaveKey('components');
});

it('withholds components under show-details: never', function () {
    expect(hdaBody('never', hdaAllowAll()))->not->toHaveKey('components');
});

it('asks the authorizer under show-details: when-authorized', function () {
    expect(hdaBody('when-authorized', hdaAllowAll()))->toHaveKey('components')
        ->and(hdaBody('when-authorized', new DenyHealthDetailsAuthorizer))->not->toHaveKey('components');
});

it('still answers the aggregate status when details are withheld', function () {
    expect(hdaBody('when-authorized', new DenyHealthDetailsAuthorizer))->toHaveKey('status');
});

it('reads an unknown show-details value as never — the fail-closed direction', function () {
    expect(hdaBody('whenAuthorized', hdaAllowAll()))->not->toHaveKey('components');
});
