<?php

declare(strict_types=1);

use Firefly\Observability\Logging\FireflyContextLogProcessor;
use Firefly\Observability\Tests\Support\ObservabilityTracingCapstoneTestCase;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

uses(ObservabilityTracingCapstoneTestCase::class);

/**
 * THE CLAIM, THROUGH THE REAL BOOT. `Log::build()` makes an `ondemand` channel out of an array no boot pass
 * and no `afterResolving('log')` hook ever sees, so before this it was the one channel in an application
 * whose lines carried none of the framework's ids. The proof is the written FILE, not the processor list on
 * a Monolog logger, because what is being closed is what an operator reads afterwards.
 *
 * THE ASSERTION IS ON THE NORMALISED FIELD NAMES, and it has to be: Laravel's own ContextLogProcessor already
 * merges the whole of Context::all() into `extra`, so the raw keys `firefly.correlation_id` and
 * `firefly.trace_id` — and therefore both VALUES — were on the line before this feature existed (verified
 * against this installed version). What was missing, and what every aggregator query is written against, is
 * `correlation_id` / `trace_id` / `span_id`: the same field names LogChannelWiring puts on a configured
 * channel. Asserting the bare value would pass without a single line of this task's code.
 */
it('dresses a channel built after boot with the framework ids', function () {
    /** @var ObservabilityTracingCapstoneTestCase $this */
    $path = sys_get_temp_dir().'/firefly-built-channel-'.bin2hex(random_bytes(4)).'.log';

    $this->app()->make(ContextRepository::class);

    Context::add(CorrelationIdFilter::CONTEXT_KEY, 'corr-built');
    Context::add(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');
    Context::add(TraceContext::SPAN_ID, '00f067aa0ba902b7');

    try {
        Log::build(['driver' => 'single', 'path' => $path])->info('after boot');

        expect(file_get_contents($path))
            ->toContain('"correlation_id":"corr-built"')
            ->toContain('"trace_id":"4bf92f3577b34da6a3ce929d0e0e4736"')
            ->toContain('"span_id":"00f067aa0ba902b7"');
    } finally {
        @unlink($path);
    }
});

/**
 * Laravel's own processor is PRESERVED inside the decorator, not replaced: `Context::add()` on an on-demand
 * channel keeps reaching the line exactly as it did before the rebinding. A decorator that swallowed it
 * would still satisfy the test above.
 */
it('keeps the application\'s own context on a channel built after boot', function () {
    /** @var ObservabilityTracingCapstoneTestCase $this */
    $path = sys_get_temp_dir().'/firefly-built-channel-'.bin2hex(random_bytes(4)).'.log';

    $this->app()->make(ContextRepository::class);

    Context::add('tenant', 'acme-tenant');

    try {
        Log::build(['driver' => 'single', 'path' => $path])->info('after boot');

        expect(file_get_contents($path))->toContain('"tenant":"acme-tenant"');
    } finally {
        @unlink($path);
    }
});

/** The contract an application resolves is the decorator, so anything that builds its own channel gets it too. */
it('rebinds the ContextLogProcessor contract to the decorator', function () {
    /** @var ObservabilityTracingCapstoneTestCase $this */
    expect($this->app()->make(ContextLogProcessorContract::class))->toBeInstanceOf(FireflyContextLogProcessor::class);
});
