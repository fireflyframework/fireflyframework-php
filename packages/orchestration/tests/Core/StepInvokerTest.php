<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Firefly\Orchestration\Core\Argument\ArgumentResolver;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\InvocationRunner;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Event\NoOpOrchestrationTracing;
use Illuminate\Container\Container;

function orchestrationInvoker(): StepInvoker
{
    return new StepInvoker(new InvocationRunner(new Container, new ArgumentResolver), new NoOpOrchestrationTracing);
}

function orchestrationInvokerContext(): ExecutionContext
{
    return ExecutionContext::start(ExecutionPattern::Saga, 's', [], [], 'c', new DateTimeImmutable);
}

it('retries a failing step retry times and records every attempt', function (): void {
    $calls = 0;
    $flaky = new ClosureInvocation(static function () use (&$calls): string {
        if (++$calls < 3) {
            throw new RuntimeException("attempt {$calls}");
        }

        return 'ok';
    });
    $context = orchestrationInvokerContext();

    expect(orchestrationInvoker()->invoke('s', 'charge', $flaky, StepOptions::of(2, 1, 0), $context, null, null))->toBe('ok')
        ->and($context->attempts('charge'))->toBe(3);
});

it('gives up after retry + 1 attempts and rethrows the last error', function (): void {
    $context = orchestrationInvokerContext();
    $failing = new ClosureInvocation(static fn (): never => throw new RuntimeException('down'));

    expect(fn () => orchestrationInvoker()->invoke('s', 'charge', $failing, StepOptions::of(1, 0, 0), $context, null, null))
        ->toThrow(RuntimeException::class, 'down')
        ->and($context->attempts('charge'))->toBe(2);
});

it('backs off between attempts, with jitter bounded by the factor', function (): void {
    $failing = new ClosureInvocation(static fn (): never => throw new RuntimeException('down'));
    $started = microtime(true);

    try {
        orchestrationInvoker()->invoke('s', 'charge', $failing, StepOptions::of(2, 20, 0, jitter: true, jitterFactor: 0.5), orchestrationInvokerContext(), null, null);
    } catch (RuntimeException) {
    }

    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.040)->toBeLessThan(1.0);
});

it('times a step out after timeoutMs', function (): void {
    $slow = new ClosureInvocation(static function (): string {
        usleep(60_000);

        return 'late';
    });

    orchestrationInvoker()->invoke('s', 'charge', $slow, StepOptions::of(0, 0, 10), orchestrationInvokerContext(), null, null);
})->throws(TimeoutException::class);

it('normalizes negative annotation defaults to "none"', function (): void {
    expect(StepOptions::of(-1, -1, -1))->toEqual(new StepOptions)
        ->and(StepOptions::fromArray(StepOptions::of(2, 5, 7, true, 0.25, 'k')->toArray()))->toEqual(new StepOptions(2, 5, 7, true, 0.25, 'k'))
        ->and(StepOptions::of(1, 1, 1, false, 0.5, 'k')->withoutIdempotency()->idempotencyKey)->toBe('');
});
