<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support\Fixtures;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;

/**
 * An `httpexchanges` endpoint answering with a fixed ring of exchanges.
 *
 * The rows are in HttpExchange::toArray()'s shape — `uri` rather than `path`, an ISO-8601 `timestamp` with
 * microseconds, `durationMs`, and `traceId` PRESENT-OR-ABSENT rather than nullable — because that shape is
 * exactly what AdminAction::exchanges() was rewritten to read after it spent a release reading a `path` and a
 * numeric timestamp the endpoint never emitted.
 *
 * Stubbed rather than recorded, unlike ObservabilityAdminTestCase, which serves real requests through a real
 * recorder and is the right harness for "does the filter record what it served". This one is the harness for
 * "does the LISTING sort, search and page", which needs rows whose order, ages and ids are known and stable —
 * a recorder's ring holds whatever the test happened to visit, in whatever order the suite ran.
 */
final readonly class HttpExchangesEndpointStub implements ActuatorEndpoint
{
    /**
     * @param  list<array<string, mixed>>  $exchanges  newest first, as the recorder returns them
     */
    public function __construct(private array $exchanges) {}

    public function endpointId(): string
    {
        return 'httpexchanges';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        return EndpointResponse::json([
            'recording' => true,
            'storage' => 'memory',
            'processLocal' => true,
            'recorded' => count($this->exchanges),
            'count' => count($this->exchanges),
            'exchanges' => $this->exchanges,
        ]);
    }
}
