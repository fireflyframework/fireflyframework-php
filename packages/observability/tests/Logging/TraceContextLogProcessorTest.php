<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Observability\Logging\TraceContextLogProcessor;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Testing\Double\RecordingTracer;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase;

// Testbench (not bare PHP) for the same reason CorrelationIdLogProcessorTest gives: the Context facade needs a
// booted application.
uses(TestCase::class);

function traceRecord(): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'hello', [], ['foo' => 'bar']);
}

it('stamps the current span ids from the tracer, plus the correlation and request ids from Context', function () {
    $tracer = new RecordingTracer;
    $span = $tracer->startSpan('work');
    Context::add('firefly.correlation_id', 'corr-1');
    Context::add('firefly.request_id', 'req-1');

    $out = (new TraceContextLogProcessor(static fn (): Tracer => $tracer))(traceRecord());

    expect($out->extra)->toBe([
        'foo' => 'bar',
        TraceContextLogProcessor::TRACE_ID => $span->traceId(),
        TraceContextLogProcessor::SPAN_ID => $span->spanId(),
        TraceContextLogProcessor::CORRELATION_ID => 'corr-1',
        TraceContextLogProcessor::REQUEST_ID => 'req-1',
    ]);
});

it('falls back to the ids TracingFilter published in Context when no span is current', function () {
    Context::add('firefly.trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');
    Context::add('firefly.span_id', '00f067aa0ba902b7');

    $out = (new TraceContextLogProcessor(static fn (): ?Tracer => null))(traceRecord());

    expect($out->extra['trace_id'])->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($out->extra['span_id'])->toBe('00f067aa0ba902b7')
        ->and($out->extra)->not->toHaveKey('correlation_id');
});

it('leaves a record untouched when nothing is known', function () {
    $record = traceRecord();

    expect((new TraceContextLogProcessor(static fn (): Tracer => new RecordingTracer))($record))->toBe($record);
});

/** The wiring half: a real Log:: write through the default channel carries the ids. Same shape as CorrelationIdLogProcessorTest's last case. */
it('is wired onto the default log channel by ObservabilityWiringProvider', function () {
    $handler = new TestHandler;
    app()->bind(TestHandler::class, fn () => $handler);

    config()->set('logging.channels.trace_test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    config()->set('logging.default', 'trace_test');

    app()->register(FireflyAutoConfigureServiceProvider::class);
    app()->register(ObservabilityServiceProvider::class);
    app()->register(ObservabilityWiringProvider::class);

    Context::add('firefly.trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');
    Context::add('firefly.request_id', 'req-9');

    Log::info('traced line');

    $matching = array_filter(
        $handler->getRecords(),
        static fn (LogRecord $record): bool => ($record->extra['trace_id'] ?? null) === '4bf92f3577b34da6a3ce929d0e0e4736'
            && ($record->extra['request_id'] ?? null) === 'req-9',
    );

    expect($matching)->not->toBeEmpty();
});
