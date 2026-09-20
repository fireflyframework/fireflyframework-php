<?php

declare(strict_types=1);

use Firefly\Observability\Tracing\NoOpSpan;
use Firefly\Observability\Tracing\NoOpTracer;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;

it('hands out non-recording spans with an invalid context and no current span', function () {
    $tracer = new NoOpTracer;
    $span = $tracer->startSpan('work', SpanKind::Server, ['k' => 'v']);

    expect($span)->toBeInstanceOf(NoOpSpan::class)
        ->and($span->isRecording())->toBeFalse()
        ->and($span->context()->isValid())->toBeFalse()
        ->and($tracer->currentSpan())->toBeNull();

    // Every setter is a fluent no-op, and end() is idempotent.
    $span->updateName('other')->setAttribute('a', 1)->setAttributes(['b' => true])->addEvent('e')
        ->setStatus(SpanStatus::Error, 'boom')->recordException(new RuntimeException('x'))->end();
    $span->end();
});

it('keeps trace() as the convenience: runs the callback with a span and returns its result', function () {
    $seen = null;
    $result = (new NoOpTracer)->trace('work', function (Span $span) use (&$seen): int {
        $seen = $span;

        return 7;
    });

    expect($result)->toBe(7)->and($seen)->toBeInstanceOf(NoOpSpan::class);
});

it('still accepts the M12 zero-argument callback shape', function () {
    expect((new NoOpTracer)->trace('work', fn (): string => 'ok'))->toBe('ok');
});
