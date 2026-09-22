<?php

declare(strict_types=1);

use Firefly\Actuator\Introspection\SensitiveValueMasker;
use Firefly\Observability\Tests\Support\ObservabilityTracingCapstoneTestCase;
use Firefly\Observability\Tracing\OpenTelemetry\OpenTelemetryTracer;
use Firefly\Observability\Tracing\Tracer;

uses(ObservabilityTracingCapstoneTestCase::class);

/**
 * The adapter, booted through the real pipeline rather than the bare fireflyApplication() helper
 * OpenTelemetryAutoConfigurationTest uses: Testbench, the actuator and observability providers together, the
 * incremental condition pass, and the #[Order(400)] precedence that lets OpenTelemetryAutoConfiguration's
 * tracer() win over ObservabilityAutoConfiguration's NoOp.
 */
it('binds the OpenTelemetry tracer ahead of the NoOp in a real boot with tracing enabled', function () {
    /** @var ObservabilityTracingCapstoneTestCase $this */
    expect($this->app()->make(Tracer::class))->toBeInstanceOf(OpenTelemetryTracer::class);
});

/**
 * SECURITY. `firefly.observability.tracing.otlp.headers` is documented as the place for a vendor's auth
 * header, and /actuator/env renders the firefly.* tree — one `FIREFLY_ACTUATOR_EXPOSE=*` away in any staging
 * environment. The key is named `headers`, which none of the masker's six original words matched, so the
 * framework introduced a key whose documented purpose is to carry a credential and its own fail-safe
 * invariant ("/env values masked") let the credential through in clear. The rule now names `headers`; this
 * pins it over the real HTTP path, on the real endpoint, with the real key — and checks that the endpoint
 * beside it is still readable, because a mask that hid the whole `otlp` block would be a different bug.
 */
it('masks the OTLP headers on /actuator/env and leaves the OTLP endpoint readable', function () {
    /** @var ObservabilityTracingCapstoneTestCase $this */
    $response = $this->getJson('/actuator/env')->assertStatus(200);

    $response->assertJsonPath('firefly.observability.tracing.otlp.headers', SensitiveValueMasker::MASK)
        ->assertJsonPath('firefly.observability.tracing.otlp.endpoint', ObservabilityTracingCapstoneTestCase::OTLP_ENDPOINT)
        ->assertJsonPath('firefly.observability.tracing.enabled', true);

    expect($this->responseBody($response))
        ->not->toContain('hcaik_SECRET')
        ->not->toContain('Bearer abc')
        ->not->toContain('x-honeycomb-team');
});
