<?php

declare(strict_types=1);

use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Observability\Cqrs\MeterRegistryCqrsMetrics;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Tests\Support\ObservabilityCapstoneTestCase;
use Firefly\Resilience\ResilienceRegistry;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

uses(ObservabilityCapstoneTestCase::class);

/** A trivial fixture "command" — only its short class name is used as the cqrs_commands_seconds `type` tag. */
final class DemoCqrsCommand {}

/**
 * The literal scrape-body accessor the brief's implementer note names as the safe fallback:
 * ActuatorDispatchAction::toResponse() always returns a plain Illuminate\Http\Response (never a
 * StreamedResponse/BinaryFileResponse), so TestResponse::streamedContent() — which asserts the base response IS
 * one of those two types — fails every call with "The response is not a streamed response." Brief-test fix (the
 * recurring "testbench HTTP body accessor" gap): use ->baseResponse->getContent() instead.
 *
 * @param  TestResponse<Response>  $response
 */
function prometheusBody(TestResponse $response): string
{
    /** @var Response $base */
    $base = $response->baseResponse;

    return (string) $base->getContent();
}

it('mounts the observability /prometheus endpoint on the actuator (gated wiring) and scrapes recorded meters', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    // Record a meter directly through the shared registry, then scrape it via the actuator route.
    /** @var MeterRegistry $registry */
    $registry = $this->app()->make(MeterRegistry::class);
    $registry->counter('demo_events_total', ['kind' => 'test'])->increment(2.0);

    // Brief-test fix: assertHeader() demands an EXACT match, but Illuminate\Http\Response::prepare() (Symfony)
    // auto-appends "; charset=utf-8" to any text/* content type that doesn't already declare one — PrometheusEndpoint
    // only sets 'text/plain; version=0.0.4', so the real HTTP header ends up as
    // 'text/plain; version=0.0.4; charset=utf-8'. assertHeaderContains() checks the substring instead (the raw
    // EndpointResponse::contentType itself — asserted verbatim in MetricsEndpointsTest — is unaffected; only the
    // HTTP-kernel-prepared response header gains the suffix).
    $this->get('/actuator/prometheus')
        ->assertStatus(200)
        ->assertHeaderContains('Content-Type', 'text/plain; version=0.0.4');

    $body = prometheusBody($this->get('/actuator/prometheus'));
    expect($body)->toContain('# TYPE demo_events_total counter')
        ->toContain('demo_events_total{kind="test"} 2')
        ->toContain('# TYPE process_resident_memory_bytes gauge');
});

it('lists metric names on /actuator/metrics', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    $this->app()->make(MeterRegistry::class)->counter('demo_events_total')->increment();

    $this->getJson('/actuator/metrics')->assertStatus(200)->assertJsonPath(
        'names',
        static fn (mixed $names): bool => is_array($names) && in_array('demo_events_total', $names, true)
    );
});

/**
 * The make-or-break end-to-end proof (§7 risks 1 + 4 TOGETHER, over the real HTTP path — not just the container
 * assertions RealProviderBootTest already covers):
 *
 * 1. Resolving Firefly\Cqrs\Metrics\CqrsMetrics from the booted container returns MeterRegistryCqrsMetrics — the
 *    M12 recorder — NOT the M10 NoOpCqrsMetrics, because ObservabilityAutoConfiguration's #[Order(500)] registers
 *    cqrsMetrics() before CqrsAutoConfiguration's #[Order(1000)] evaluates its #[ConditionalOnMissingBean] (RISK 4).
 * 2. Recording through that real CqrsMetrics — and independently tripping a real ResilienceRegistry-backed
 *    CircuitBreaker — lands in the SAME MeterRegistry that MeterBindingsPass wired the CB gauge into at boot.
 * 3. Both show up in a live GET /actuator/prometheus scrape, which only works at all because the property-gated
 *    #[Lazy] PrometheusEndpoint survived condition filtering and resolved cleanly post-EagerSingletons (RISK 1).
 */
it('proves the real CqrsMetrics recorder and a tripped circuit breaker both reach the /prometheus scrape', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    $metrics = $this->app()->make(CqrsMetrics::class);
    expect($metrics)->toBeInstanceOf(MeterRegistryCqrsMetrics::class);

    $metrics->recordCommandSuccess(new DemoCqrsCommand, 0.05);

    /** @var ResilienceRegistry $resilience */
    $resilience = $this->app()->make(ResilienceRegistry::class);
    $breaker = $resilience->circuitBreaker('demo'); // failure-threshold=1 (see ObservabilityCapstoneTestCase)
    try {
        $breaker->call(function (): never {
            throw new RuntimeException('trip it');
        });
    } catch (RuntimeException) {
        // expected — the single failure opens the breaker (failure-threshold=1)
    }
    expect($breaker->state())->toBe('open');

    $body = prometheusBody($this->get('/actuator/prometheus'));

    expect($body)->toContain('# TYPE cqrs_commands_seconds summary')
        ->toContain('type="DemoCqrsCommand"')
        ->toContain('outcome="success"')
        ->toContain('# TYPE resilience_circuit_breaker_state gauge')
        ->toContain('resilience_circuit_breaker_state{name="demo"} 1');
});
