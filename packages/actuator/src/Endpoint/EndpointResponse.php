<?php

declare(strict_types=1);

namespace Firefly\Actuator\Endpoint;

/**
 * An endpoint result carrying an explicit HTTP status + content type — richer than the spec's sketch `?array` so
 * health can answer 503-on-DOWN and the Prometheus endpoint can answer text/plain (a deliberate contract shape).
 * A null return from ActuatorEndpoint::handle() (never an EndpointResponse) is the 404 signal.
 */
final readonly class EndpointResponse
{
    /**
     * @param  array<mixed>|string  $body
     */
    private function __construct(
        public int $status,
        public array|string $body,
        public string $contentType,
    ) {}

    /**
     * @param  array<mixed>  $body
     */
    public static function json(array $body, int $status = 200): self
    {
        return new self($status, $body, 'application/json');
    }

    public static function text(string $body, int $status = 200, string $contentType = 'text/plain'): self
    {
        return new self($status, $body, $contentType);
    }
}
