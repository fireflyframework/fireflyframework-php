<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;

function fakeEndpoint(string $id): ActuatorEndpoint
{
    return new class($id) implements ActuatorEndpoint
    {
        public function __construct(private string $id) {}

        public function endpointId(): string
        {
            return $this->id;
        }

        public function enabled(): bool
        {
            return true;
        }

        // Narrowed to a non-nullable return (covariant, allowed against the interface's `?EndpointResponse`):
        // this fixture never returns null, and PHPStan level max flags an unused nullable as return.unusedType.
        public function handle(EndpointRequest $request): EndpointResponse
        {
            return EndpointResponse::json(['id' => $this->id, 'sub' => $request->subPath]);
        }
    };
}

it('registers and resolves endpoints by id', function () {
    $registry = new ActuatorRegistry;
    $registry->register(fakeEndpoint('health'));
    $registry->register(fakeEndpoint('info'));

    expect($registry->get('health'))->not->toBeNull()
        ->and($registry->get('missing'))->toBeNull()
        ->and($registry->ids())->toEqual(['health', 'info']);
});

it('builds a json EndpointResponse with defaults and a text one with a content type', function () {
    $json = EndpointResponse::json(['a' => 1]);
    $text = EndpointResponse::text('hello', 503, 'text/plain; version=0.0.4');

    expect($json->status)->toBe(200)
        ->and($json->contentType)->toBe('application/json')
        ->and($json->body)->toBe(['a' => 1])
        ->and($text->status)->toBe(503)
        ->and($text->contentType)->toBe('text/plain; version=0.0.4')
        ->and($text->body)->toBe('hello');
});
