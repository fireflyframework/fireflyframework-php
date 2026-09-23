<?php

declare(strict_types=1);

use Firefly\Observability\Logging\FireflyContextLogProcessor;
use Firefly\Observability\Logging\TraceContextLogProcessor;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Container\Container;
use Illuminate\Log\Context\ContextLogProcessor as LaravelContextLogProcessor;
use Illuminate\Support\Facades\Context;
use Monolog\Level;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase;

// Testbench, for the reason CorrelationIdLogProcessorTest gives: the Context facade needs a booted application,
// and both the decorator and the processors it wraps read Context.
uses(TestCase::class);

function fireflyContextRecord(): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'hello', [], []);
}

it('adds the trace and span ids to a record', function () {
    Context::add(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');
    Context::add(TraceContext::SPAN_ID, '00f067aa0ba902b7');

    $record = (new FireflyContextLogProcessor(Container::getInstance()))(fireflyContextRecord());

    expect($record->extra[TraceContextLogProcessor::TRACE_ID])->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($record->extra[TraceContextLogProcessor::SPAN_ID])->toBe('00f067aa0ba902b7');
});

it('adds the correlation id', function () {
    Context::add(CorrelationIdFilter::CONTEXT_KEY, 'corr-9');

    expect((new FireflyContextLogProcessor(Container::getInstance()))(fireflyContextRecord())->extra)
        ->toHaveKey(TraceContextLogProcessor::CORRELATION_ID, 'corr-9');
});

it('still applies Laravel\'s own context processor', function () {
    Context::add('tenant', 'acme');

    expect((new FireflyContextLogProcessor(Container::getInstance(), new LaravelContextLogProcessor))(fireflyContextRecord())->extra)
        ->toHaveKey('tenant');
});

/**
 * The framework's ids are written OVER an application context key of the same name, never under it: Laravel's
 * processor runs first and merges Context::all() verbatim, so a `Context::add('trace_id', …)` would otherwise
 * decide what a log aggregator reads as the trace id. Same precedence ErrorResponse::toArray() gives its
 * standard members over extensions, and for the same reason.
 */
it('writes the framework ids over an application context key of the same name', function () {
    Context::add(TraceContextLogProcessor::TRACE_ID, 'forged');
    Context::add(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');

    expect((new FireflyContextLogProcessor(Container::getInstance(), new LaravelContextLogProcessor))(fireflyContextRecord())->extra)
        ->toHaveKey(TraceContextLogProcessor::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');
});

it('adds nothing and throws nothing when no ids are published', function () {
    expect((new FireflyContextLogProcessor(Container::getInstance()))(fireflyContextRecord())->extra)
        ->not->toHaveKey(TraceContextLogProcessor::TRACE_ID);
});
