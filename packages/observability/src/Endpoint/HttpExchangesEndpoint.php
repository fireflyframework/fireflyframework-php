<?php

declare(strict_types=1);

namespace Firefly\Observability\Endpoint;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Observability\HttpExchanges\HttpExchangeRecorder;

/**
 * /actuator/httpexchanges — the last N requests the application served, newest first.
 *
 * WHY THE PAYLOAD CARRIES FIVE FIELDS BESIDES `exchanges`, when Spring's carries none. Under PHP-FPM the default
 * (in-memory) recorder can only ever answer with an EMPTY list: each request is a fresh process, so the ring
 * this endpoint reads belongs to the process rendering it and that process has served exactly one request — the
 * one currently in flight, which HttpExchangeFilter has not recorded yet because it records on the way out. An
 * operator handed `{"exchanges": []}` concludes their application is serving no traffic and goes looking for a
 * routing bug that does not exist. So the endpoint states its own storage model:
 *
 *   "storage": "memory", "processLocal": true   → the buffer is this worker's only; point
 *                                                 firefly.observability.httpexchanges.store at a cache store.
 *   "recording": false                          → firefly.observability.httpexchanges.enabled is off, so
 *                                                 HttpExchangeFilter was never registered and nothing is being
 *                                                 written at all.
 *   "recorded" vs "count"                       → total ever recorded vs currently buffered, i.e. how much
 *                                                 history the ring has already evicted.
 *
 * That is the difference between a buffer that is empty and a buffer that is broken, and it is the whole reason
 * this endpoint is not simply un-registered when recording is off: an absent endpoint is a 404 that tells an
 * operator nothing, while a present one that says `"recording": false` names the flag to flip.
 *
 * NOT GATED on firefly.observability.metrics.enabled — http exchanges are a separate feature with a separate
 * switch (see HttpExchangeFilter), and the endpoint stays mounted even when recording is off precisely so it can
 * say so. It is not in the secure-by-default exposure list (`health,info`), so reaching it over HTTP is always a
 * deliberate act by the operator; the admin dashboard renders it in-process through ActuatorRegistry regardless.
 *
 * `handle()` narrows the contract's `?EndpointResponse` to the non-nullable type (the covariant narrowing
 * BeansEndpoint/InfoEndpoint/EnvEndpoint use): there is no sub-resource to 404 on, so a body is always produced
 * and PHPStan at level max flags the nullable type as dead code otherwise.
 *
 * #[Lazy] is REQUIRED, not decorative — the same hazard MetricsEndpoint documents: HttpExchangeRecorder is a
 * #[Bean] resolved at BootPhase::EagerSingletons (900), the phase this #[Component] would otherwise be eagerly
 * constructed at. #[Lazy] defers construction to ActuatorRouteRegistrar's own resolve at
 * BootPhase::WiringPasses (1000), strictly after the recorder is bound.
 */
#[Component]
#[Lazy]
final class HttpExchangesEndpoint implements ActuatorEndpoint
{
    private const ENABLED_KEY = 'firefly.observability.httpexchanges.enabled';

    public function __construct(
        private readonly HttpExchangeRecorder $recorder,
        private readonly Config $config,
    ) {}

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
        $exchanges = $this->recorder->exchanges();

        $limit = $this->limit($request);
        if ($limit !== null) {
            $exchanges = array_slice($exchanges, 0, $limit);
        }

        return EndpointResponse::json([
            'exchanges' => array_map(static fn ($exchange): array => $exchange->toArray(), $exchanges),
            'count' => count($exchanges),
            'capacity' => $this->recorder->capacity(),
            'recorded' => $this->recorder->recorded(),
            'storage' => $this->recorder->storage(),
            'processLocal' => $this->recorder->processLocal(),
            'recording' => $this->recording(),
        ]);
    }

    /**
     * `?limit=N` trims the newest-first list, so a dashboard panel showing ten rows fetches ten rows instead of
     * the whole ring. Anything that is not a positive integer is ignored rather than rejected: a management
     * endpoint answering 400 to a malformed query string turns a cosmetic client bug into a blank panel, and
     * there is a correct, obvious answer available (the unfiltered list).
     */
    /**
     * Whether HttpExchangeFilter is actually REGISTERED — not merely whether the flag reads as truthy.
     *
     * The filter is gated by #[ConditionalOnProperty(havingValue: 'true', matchIfMissing: true)], and
     * ConditionEvaluator compares the STRINGIFIED config value against the literal 'true'. A truthy spelling
     * that is not that literal — `FIREFLY_HTTPEXCHANGES_ENABLED=1`, which Laravel's env() hands back as the
     * string "1", or 'on'/'yes' — therefore drops the filter, while Config::bool() would happily call it
     * enabled. Read through bool(), this endpoint would answer `"recording": true` beside a permanently empty
     * list: exactly the "my application must be serving no traffic" dead end that this field exists to prevent.
     * Mirroring the condition's own comparison keeps the reported flag equal to the observable behaviour.
     */
    private function recording(): bool
    {
        $value = $this->config->has(self::ENABLED_KEY) ? $this->config->get(self::ENABLED_KEY) : null;

        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && (string) $value === 'true';
    }

    private function limit(EndpointRequest $request): ?int
    {
        $raw = $request->query['limit'] ?? null;

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }
}
