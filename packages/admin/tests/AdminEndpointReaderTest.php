<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Admin\AdminEndpointReader;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

/** @param array<string,mixed> $body */
function stubEndpoint(string $id, array $body, bool $enabled = true): ActuatorEndpoint
{
    return new class($id, $body, $enabled) implements ActuatorEndpoint
    {
        /** @param array<string,mixed> $body */
        public function __construct(
            private readonly string $id,
            private readonly array $body,
            private readonly bool $enabled,
        ) {}

        public function endpointId(): string
        {
            return $this->id;
        }

        public function enabled(): bool
        {
            return $this->enabled;
        }

        public function handle(EndpointRequest $request): ?EndpointResponse
        {
            if ($request->subPath !== []) {
                // 'missing' models an unknown sub-resource, which the contract says is a null return.
                return $request->subPath[0] === 'missing'
                    ? null
                    : EndpointResponse::json(['sub' => $request->subPath[0]]);
            }

            return EndpointResponse::json($this->body);
        }
    };
}

/** @param array<string,mixed> $firefly */
function reader(ActuatorRegistry $registry, array $firefly = []): AdminEndpointReader
{
    return new AdminEndpointReader($registry, new Config(new Repository(['firefly' => $firefly])));
}

it('reads a registered endpoint in-process', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('beans', ['beans' => [['class' => 'A']]]));

    expect(reader($registry)->read('beans'))->toBe(['beans' => [['class' => 'A']]]);
});

// The dashboard deliberately ignores ExposureModel — that is the whole point, since exposure defaults to
// health,info and nobody should have to publish env to the world to read it locally.
it('reads an endpoint that is registered but NOT exposed over HTTP', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('env', ['firefly' => ['a' => 1]]));

    $r = reader($registry, ['management' => ['endpoints' => ['web' => ['exposure' => ['include' => 'health,info']]]]]);

    expect($r->has('env'))->toBeTrue()
        ->and($r->read('env'))->toBe(['firefly' => ['a' => 1]]);
});

// ...but a per-endpoint kill switch means "off", not "unpublished", so it IS honoured.
it('honours the per-endpoint kill switch', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('env', ['firefly' => []]));

    $r = reader($registry, ['management' => ['endpoint' => ['env' => ['enabled' => false]]]]);

    expect($r->has('env'))->toBeFalse()
        ->and($r->read('env'))->toBeNull()
        ->and($r->available())->toBe([]);
});

it('honours an endpoint that reports itself disabled', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('metrics', [], enabled: false));

    expect(reader($registry)->available())->toBe([]);
});

it('returns null for an unknown endpoint rather than throwing', function () {
    expect(reader(new ActuatorRegistry)->read('nope'))->toBeNull();
});

// One broken health indicator should degrade its panel, not take the whole dashboard down.
it('degrades to null when an endpoint throws', function () {
    $registry = new ActuatorRegistry;
    $registry->register(new class implements ActuatorEndpoint
    {
        public function endpointId(): string
        {
            return 'health';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function handle(EndpointRequest $request): ?EndpointResponse
        {
            throw new RuntimeException('indicator exploded');
        }
    });

    expect(reader($registry)->read('health'))->toBeNull();
});

it('passes a sub-path through, which is how metrics are drilled into', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('metrics', ['names' => ['http.requests']]));

    expect(reader($registry)->read('metrics', ['http.requests']))->toBe(['sub' => 'http.requests']);
});

// A null handle() is the contract's 404 signal (an unknown sub-resource), not an error.
it('returns null when an endpoint reports an unknown sub-resource', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('metrics', ['names' => []]));

    expect(reader($registry)->read('metrics', ['missing']))->toBeNull();
});

it('reports a successful write and refuses one to an unknown endpoint', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('loggers', ['levels' => []]));

    expect(reader($registry)->write('loggers', ['app'], ['level' => 'DEBUG']))->toBeTrue()
        ->and(reader($registry)->write('nope', [], []))->toBeFalse();
});

it('lists only the endpoints that are actually available', function () {
    $registry = new ActuatorRegistry;
    $registry->register(stubEndpoint('health', []));
    $registry->register(stubEndpoint('beans', []));
    $registry->register(stubEndpoint('metrics', [], enabled: false));

    expect(reader($registry)->available())->toBe(['health', 'beans']);
});
