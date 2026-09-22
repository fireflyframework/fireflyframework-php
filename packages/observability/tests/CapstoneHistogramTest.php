<?php

declare(strict_types=1);

use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Observability\Tests\Support\ObservabilityHistogramCapstoneTestCase;

uses(ObservabilityHistogramCapstoneTestCase::class);

/** A fixture command distinct from CapstoneObservabilityIntegrationTest's DemoCqrsCommand (one Pest process, unique globals). */
final class HistogramDemoCommand {}

it('scrapes the configured meter as a histogram and every other timer as the summary it always was', function () {
    /** @var ObservabilityHistogramCapstoneTestCase $this */
    $this->get('/demo/1')->assertStatus(200);
    $this->get('/demo/2')->assertStatus(200);
    $this->app()->make(CqrsMetrics::class)->recordCommandSuccess(new HistogramDemoCommand, 0.02);

    $body = $this->responseBody($this->get('/actuator/prometheus'));

    expect($body)->toContain('# TYPE http_server_requests_seconds histogram')
        ->toContain('http_server_requests_seconds_bucket{')
        ->toContain('le="+Inf"} 2')
        ->toContain('http_server_requests_seconds_count{')
        ->toContain('# TYPE cqrs_commands_seconds summary')
        ->not->toContain('cqrs_commands_seconds_bucket');
});

it('keeps the /metrics JSON shape untouched for a bucketed timer', function () {
    /** @var ObservabilityHistogramCapstoneTestCase $this */
    $this->get('/demo/3')->assertStatus(200);

    $this->getJson('/actuator/metrics/http_server_requests_seconds')
        ->assertStatus(200)
        ->assertJsonPath('measurements.0.statistic', 'TOTAL_TIME');
});
