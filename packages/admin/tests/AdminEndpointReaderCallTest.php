<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Admin\AdminEndpointReader;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

it('makes a raw in-process exchange with trusted admin origin and preserves refusals', function (): void {
    $endpoint = new class implements ActuatorEndpoint
    {
        public ?EndpointRequest $seen = null;

        public bool $broken = false;

        public function endpointId(): string
        {
            return 'flags';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function handle(EndpointRequest $request): EndpointResponse
        {
            if ($this->broken) {
                throw new RuntimeException('down');
            }

            $this->seen = $request;

            return EndpointResponse::text('{"error":"conflict","message":"m"}', 409, 'application/json');
        }
    };
    $registry = new ActuatorRegistry;
    $registry->register($endpoint);
    $reader = new AdminEndpointReader($registry, new Config(new Repository([])));

    $response = $reader->call('POST', 'flags', ['k'], [], ['action' => 'put'], '{"action":"put"}');

    expect([$response?->status, $response?->body])->toBe([409, '{"error":"conflict","message":"m"}'])
        ->and([$endpoint->seen?->method, $endpoint->seen?->subPath, $endpoint->seen?->query, $endpoint->seen?->rawBody, $endpoint->seen?->origin])
        ->toBe(['POST', ['k'], [], '{"action":"put"}', 'admin'])
        ->and($reader->call('GET', 'nothing'))->toBeNull();

    $endpoint->broken = true;

    expect($reader->call('GET', 'flags'))->toBeNull();
});
