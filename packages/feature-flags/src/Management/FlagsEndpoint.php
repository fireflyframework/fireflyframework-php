<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Management;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Container as FireflyContainer;
use Firefly\FeatureFlags\Definition\Json;
use JsonException;

/**
 * `/actuator/flags` (spec §4.8): GET lists, GET /{key} describes, POST /{key} evaluates or writes. Unexposed
 * until named in firefly.management.endpoints.web.exposure.include; writes need management.writes AND a store.
 *
 * The body is encoded with Json::encode() and handed over as text: identical JSON in both frameworks means a
 * float variant stays `1.0` and an empty object stays `{}`, which the dispatcher's own json_encode() of an array
 * would not keep. For the same reason a POST is read from the raw JSON body when there is one (the dispatcher's
 * parsed array has already turned `{}` into `[]`). `?via=admin` is the admin page's in-process call: its actor
 * fallback is `admin`.
 */
#[Component]
final class FlagsEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly FireflyContainer $beans) {}

    public function endpointId(): string
    {
        return 'flags';
    }

    public function enabled(): bool
    {
        return $this->beans->has(FlagManagement::class);
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        $management = $this->beans->has(FlagManagement::class) ? $this->beans->get(FlagManagement::class) : null;
        if (! $management instanceof FlagManagement || count($request->subPath) > 1) {
            return null;
        }

        $key = $request->subPath[0] ?? null;

        try {
            if ($request->method === 'GET') {
                return self::json($key === null ? $management->overview() : $management->describe($key));
            }

            if ($request->method === 'POST' && $key !== null) {
                return self::json($management->apply($key, self::body($request), ($request->query['via'] ?? null) === 'admin' ? 'admin' : 'actuator'));
            }
        } catch (FlagManagementException $refused) {
            return self::json($refused->toArray(), $refused->error->status());
        }

        return null;
    }

    /**
     * @throws FlagManagementException
     */
    private static function body(EndpointRequest $request): mixed
    {
        if ($request->rawBody === null || trim($request->rawBody) === '') {
            return $request->body;
        }

        try {
            return Json::decode($request->rawBody);
        } catch (JsonException) {
            throw new FlagManagementException(ManagementError::BadRequest, 'The request body is not valid JSON.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function json(array $body, int $status = 200): EndpointResponse
    {
        return EndpointResponse::text(Json::encode($body), $status, 'application/json');
    }
}
