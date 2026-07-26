<?php

declare(strict_types=1);

namespace Firefly\Actuator\Endpoint;

/**
 * The request an ActuatorEndpoint receives — the HTTP method, the path segments AFTER the endpoint id (e.g.
 * /actuator/health/liveness → subPath ['liveness']), the parsed query, and the parsed JSON/form body. Built by
 * ActuatorDispatchAction from the illuminate Request; scalar-only so endpoints stay trivially testable.
 */
final readonly class EndpointRequest
{
    /**
     * @param  list<string>  $subPath
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public string $method,
        public array $subPath,
        public array $query = [],
        public array $body = [],
    ) {}
}
