<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Tests\Support\RawBodyCapstoneTestCase;

uses(RawBodyCapstoneTestCase::class);

it('hands a JSON request\'s text to the endpoint beside the parsed body, and nothing for a form', function (): void {
    /** @var RawBodyCapstoneTestCase $this */
    $endpoint = new class implements ActuatorEndpoint
    {
        /** @var list<EndpointRequest> */
        public array $seen = [];

        public function endpointId(): string
        {
            return 'capture';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function handle(EndpointRequest $request): EndpointResponse
        {
            $this->seen[] = $request;

            return EndpointResponse::json(['ok' => true]);
        }
    };
    /** @var ActuatorRegistry $registry */
    $registry = $this->app()->make(ActuatorRegistry::class);
    $registry->register($endpoint);

    $this->call('POST', '/actuator/capture', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"variants":{"none":{}}}')->assertOk();
    $this->post('/actuator/capture', ['level' => 'debug'])->assertOk();

    expect($endpoint->seen[0]->rawBody)->toBe('{"variants":{"none":{}}}')
        ->and($endpoint->seen[0]->body)->toBe(['variants' => ['none' => []]])
        ->and($endpoint->seen[1]->rawBody)->toBeNull()
        ->and(new EndpointRequest('GET', []))->toHaveProperty('rawBody', null);
});
