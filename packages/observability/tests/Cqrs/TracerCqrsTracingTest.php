<?php

declare(strict_types=1);

use Firefly\Observability\Cqrs\TracerCqrsTracing;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Testing\Double\RecordingTracer;

/** Distinct from MeterRegistryCqrsMetricsTest's OpenAccountCommand: every Pest file shares one process, so global fixture names must be unique. */
final class TracedOpenAccountCommand {}

final class TracedFindAccountQuery {}

it('wraps a command and a query in INTERNAL spans named by the short class name', function () {
    $tracer = new RecordingTracer;
    $tracing = new TracerCqrsTracing($tracer);

    expect($tracing->traceCommand(new TracedOpenAccountCommand, fn (): string => 'opened'))->toBe('opened')
        ->and($tracing->traceQuery(new TracedFindAccountQuery, fn (): int => 1))->toBe(1);

    [$command, $query] = $tracer->recorded();
    expect($command->name)->toBe('TracedOpenAccountCommand')
        ->and($command->kind)->toBe(SpanKind::Internal)
        ->and($command->attributes)->toBe(['firefly.cqrs.kind' => 'command', 'firefly.cqrs.message' => TracedOpenAccountCommand::class])
        ->and($command->ended)->toBeTrue()
        ->and($query->name)->toBe('TracedFindAccountQuery')
        ->and($query->attributes['firefly.cqrs.kind'])->toBe('query');
});

it('records a failing handler as ERROR and rethrows', function () {
    $tracer = new RecordingTracer;

    expect(fn () => (new TracerCqrsTracing($tracer))->traceCommand(new TracedOpenAccountCommand, function (): never {
        throw new RuntimeException('insufficient funds');
    }))->toThrow(RuntimeException::class);

    expect($tracer->recorded()[0]->status)->toBe(SpanStatus::Error)
        ->and($tracer->recorded()[0]->exception?->getMessage())->toBe('insufficient funds');
});
