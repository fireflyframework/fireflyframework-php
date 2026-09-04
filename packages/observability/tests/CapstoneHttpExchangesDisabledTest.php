<?php

declare(strict_types=1);

use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Observability\Cqrs\MeterRegistryCqrsMetrics;
use Firefly\Observability\HttpExchanges\HttpExchangeRecorder;
use Firefly\Observability\Tests\Support\ObservabilityHttpExchangesDisabledCapstoneTestCase;

uses(ObservabilityHttpExchangesDisabledCapstoneTestCase::class);

/**
 * The config-flag proof, disabled side. firefly.observability.httpexchanges.enabled=false must stop the
 * RECORDING — HttpExchangeFilter's #[ConditionalOnProperty] drops it during condition filtering, so
 * FilterChainRegistrar never pushes it onto the HTTP kernel and no request is retained anywhere — while leaving
 * everything else standing:
 *
 *  - The endpoint stays MOUNTED and answers 200 with "recording": false. This is the deliberate choice: an
 *    un-registered endpoint would 404, and a 404 tells an operator staring at a blank dashboard panel nothing at
 *    all, whereas "recording": false names the exact flag to flip.
 *  - The recorder BEAN stays bound, because both endpoints depend on it and both have something true to say
 *    when recording is off. Cost when disabled: one empty array.
 *  - Metrics are untouched. The two features have separate switches on purpose — metrics aggregate, http
 *    exchanges retain individual requests — and refusing the second must not cost you the first.
 */
it('stops recording without unmounting the endpoint that explains why it is empty', function () {
    /** @var ObservabilityHttpExchangesDisabledCapstoneTestCase $this */
    $this->get('/demo/7')->assertStatus(200);

    $this->getJson('/actuator/httpexchanges')
        ->assertStatus(200)
        ->assertJsonPath('exchanges', [])
        ->assertJsonPath('count', 0)
        ->assertJsonPath('recorded', 0)
        ->assertJsonPath('recording', false);

    expect($this->app()->bound(HttpExchangeRecorder::class))->toBeTrue();
});

it('leaves metrics entirely alone when only http exchanges are switched off', function () {
    /** @var ObservabilityHttpExchangesDisabledCapstoneTestCase $this */
    expect($this->app()->make(CqrsMetrics::class))->toBeInstanceOf(MeterRegistryCqrsMetrics::class);

    $this->get('/actuator/prometheus')->assertStatus(200);
    $this->getJson('/actuator/metrics')->assertStatus(200);
});
