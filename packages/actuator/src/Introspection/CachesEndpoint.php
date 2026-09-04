<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Illuminate\Contracts\Config\Repository;

/**
 * Lists the cache stores this application has configured — Laravel's `cache.stores` — with each store's driver
 * and which one `cache.default` selects. Spring Boot Actuator's /actuator/caches.
 *
 * GET /actuator/caches            -> every store
 * GET /actuator/caches/{name}     -> one store, or 404 when no store by that name is configured
 *
 * READ-ONLY, DELIBERATELY. Spring's endpoint also answers DELETE (clear one cache) and DELETE on the index
 * (clear them all), and this one does not. Cache eviction is a destructive, unauthenticated-by-default
 * operation whose blast radius is a cold cache in production, and firefly/actuator has NO authorization story
 * of its own: it deliberately carries no Actuator -> Security edge (see deptrac.yaml), so the only thing
 * standing between a caller and the button would be ExposureModel — a publication list, not an access-control
 * decision. `caches` is not in the default exposure list, but "off by default" is not the same as
 * "authorized", and an endpoint that flushes production caches must be able to say WHO asked. A GET-only
 * endpoint is the honest shape until that story exists; a POST (the verb the actuator route actually mounts
 * alongside GET) is answered 404 rather than being quietly accepted or silently ignored.
 *
 * WHAT IS NOT REPORTED. Only `name`, `driver` and `default` — never the rest of the store's config array. A
 * store definition routinely carries credentials (`cache.stores.dynamodb.key`/`.secret`, memcached SASL
 * usernames and passwords, a `dsn` with an inline password), and this endpoint has the same no-authorization
 * problem as eviction does. The masking rule /env and /configprops use would cover `key` and `secret` by name,
 * but not a password embedded in a URL, so the honest answer is to publish the two facts an operator actually
 * needs — which driver backs this store, and which store is the default — and nothing else.
 *
 * Return type stays the nullable `?EndpointResponse` (unlike its sibling introspection endpoints): an unknown
 * store name and a POST both genuinely 404, so the null branch is live code, not dead.
 */
#[Component]
final class CachesEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly Repository $config) {}

    public function endpointId(): string
    {
        return 'caches';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        // Tested as "is it the mutating verb?" rather than "is it GET?" because ActuatorRouteRegistrar mounts
        // the dispatch route with match(['GET','POST']) and Laravel's Router answers HEAD wherever it answers
        // GET — so Symfony's getMethod() legitimately reports HEAD here, and a `!== 'GET'` guard would 404 a
        // perfectly ordinary HEAD probe.
        if ($request->method === 'POST') {
            return null;
        }

        $caches = $this->caches();

        if ($request->subPath === []) {
            return EndpointResponse::json(['default' => $this->defaultStore(), 'caches' => $caches]);
        }

        // One segment only: /actuator/caches/redis/anything is not a resource this endpoint has.
        if (count($request->subPath) !== 1) {
            return null;
        }

        $store = $caches[$request->subPath[0]] ?? null;

        return $store === null ? null : EndpointResponse::json($store);
    }

    /**
     * Keyed by store name — the shape Spring's /caches uses, the shape the sub-path lookup needs, and the shape
     * a dashboard indexes by. Left in `cache.stores` order rather than sorted: that order is AUTHORED, in a
     * config file somebody wrote, and therefore already stable across machines — unlike the filesystem-walk
     * order /configprops has to sort away. As on /configprops, an empty map encodes as `[]`, not `{}`.
     *
     * A store whose `driver` is missing or non-string reports 'unknown' rather than being dropped: the store IS
     * configured, something is wrong with how, and hiding the row would hide the defect.
     *
     * @return array<string, array{name: string, driver: string, default: bool}>
     */
    private function caches(): array
    {
        $default = $this->defaultStore();

        /** @var array<array-key, mixed> $stores */
        $stores = (array) $this->config->get('cache.stores', []);

        $caches = [];
        foreach ($stores as $name => $store) {
            $key = (string) $name;
            $driver = is_array($store) && isset($store['driver']) && is_string($store['driver']) ? $store['driver'] : 'unknown';

            $caches[$key] = ['name' => $key, 'driver' => $driver, 'default' => $key === $default];
        }

        return $caches;
    }

    /**
     * `cache.default` is null on a container that has no cache config at all (a bare skeleton, or a Lumen app
     * that never published config/cache.php). Reporting null — rather than inventing 'file' to match Laravel's
     * shipped default — keeps the payload a description of THIS application, not of the framework's defaults.
     */
    private function defaultStore(): ?string
    {
        $default = $this->config->get('cache.default');

        return is_string($default) ? $default : null;
    }
}
