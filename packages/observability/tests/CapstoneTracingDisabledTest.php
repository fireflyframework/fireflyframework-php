<?php

declare(strict_types=1);

use Firefly\Observability\Tests\Support\ObservabilityCapstoneTestCase;
use Firefly\Observability\Tracing\NoOpTracer;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Web\TracingFilter;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;

uses(ObservabilityCapstoneTestCase::class);

/** `object` on purpose — see tracingKernel() in CapstoneTracingHttpServerTest. */
function disabledTracingKernel(ObservabilityCapstoneTestCase $case): object
{
    return $case->app()->make(HttpKernelContract::class);
}

/**
 * The default posture, over the real pipeline: tracing.enabled unset means the NoOp tracer, no TracingFilter
 * on the kernel, and an exchange row with no traceId — an inbound traceparent is ignored, not half-handled.
 */
it('leaves tracing off by default: NoOp tracer, no filter, no trace id anywhere', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    $kernel = disabledTracingKernel($this);

    expect($this->app()->make(Tracer::class))->toBeInstanceOf(NoOpTracer::class)
        ->and($kernel instanceof FoundationHttpKernel && $kernel->hasMiddleware(TracingFilter::class))->toBeFalse();

    $this->withHeaders(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->get('/demo/1')->assertStatus(200);

    /** @var array<mixed> $body */
    $body = json_decode($this->responseBody($this->getJson('/actuator/httpexchanges')), true, 512, JSON_THROW_ON_ERROR);
    $rows = $body['exchanges'] ?? null;
    $first = is_array($rows) ? ($rows[0] ?? null) : null;

    expect($rows)->toBeArray()->toHaveCount(1)
        ->and($first)->toBeArray()
        ->and(is_array($first) && array_key_exists('traceId', $first))->toBeFalse();
});
