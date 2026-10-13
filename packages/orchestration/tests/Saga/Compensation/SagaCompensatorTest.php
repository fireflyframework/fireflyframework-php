<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Orchestration\Core\CompensationPolicy;
use Firefly\Orchestration\Core\Exception\CompensationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Saga\Compensation\CompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\CompensationErrorResult;
use Firefly\Orchestration\Saga\Compensation\DefaultCompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\FailFastCompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\RetryWithBackoffCompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\SagaCompensator;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Tests\Support\RecordingLogger;
use Firefly\Orchestration\Tests\Support\RecordingOrchestrationEvents;
use Firefly\Orchestration\Tests\Support\TestSupport;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Config\Repository;

/**
 * Builds a saga whose compensations append to $log, with `failing` compensations throwing `times` times
 * (PHP_INT_MAX = always) before succeeding.
 *
 * @param  array<string, list<string>>  $steps  step id => dependsOn
 * @param  array<string, int>  $failing
 * @param  list<string>  $log
 * @param  list<string>  $critical
 */
function orchestrationCompensationSaga(array $steps, array $failing, array &$log, array $critical = [], int $compensationRetry = -1): SagaDefinition
{
    $builder = SagaBuilder::saga('order');
    // One counter per step, shared by reference: a loop variable captured by reference would be the SAME
    // variable in every closure.
    $remaining = $failing;
    foreach ($steps as $id => $dependsOn) {
        $id = (string) $id;
        $builder = $builder->step($id)
            ->dependsOn(...$dependsOn)
            ->handler(static fn (): string => "did {$id}")
            ->compensation(static function (mixed $result) use ($id, &$log, &$remaining): string {
                $log[] = $id;
                if (($remaining[$id] ?? 0) > 0) {
                    $remaining[$id]--;

                    throw new RuntimeException("cannot undo {$id}");
                }

                return "undid {$id}";
            })
            ->compensationRetry($compensationRetry)
            ->compensationBackoffMs(1)
            ->compensationCritical(in_array($id, $critical, true))
            ->add();
    }

    return $builder->build();
}

/** @param list<string> $completed */
function orchestrationCompletedContext(SagaDefinition $saga, array $completed): ExecutionContext
{
    $context = ExecutionContext::start(ExecutionPattern::Saga, $saga->name, [], [], 'c-1', new DateTimeImmutable);
    foreach ($completed as $id) {
        $context->putResult($id, "did {$id}");
        $context->setStepStatus($id, StepStatus::Done);
        $context->markCompleted($id);
    }

    return $context;
}

function orchestrationCompensator(CompensationPolicy $policy, CompensationErrorHandler $handler, ?RecordingOrchestrationEvents $events = null, ?RecordingLogger $logger = null, ?ResilienceRegistry $resilience = null): SagaCompensator
{
    return new SagaCompensator(TestSupport::invoker(), TestSupport::engineSupport(events: $events, logger: $logger), $policy, $handler, $resilience);
}

it('STRICT_SEQUENTIAL undoes in reverse completion order and, by default, continues past a failure', function (): void {
    $log = [];
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a'], 'c' => ['b']], ['b' => PHP_INT_MAX], $log);
    $context = orchestrationCompletedContext($saga, ['a', 'b', 'c']);
    $events = new RecordingOrchestrationEvents;

    orchestrationCompensator(CompensationPolicy::StrictSequential, new DefaultCompensationErrorHandler, $events)->compensate($saga, $context);

    expect($log)->toBe(['c', 'b', 'a'])
        ->and($context->stepStatus('c'))->toBe(StepStatus::Compensated)
        ->and($context->stepStatus('b'))->toBe(StepStatus::CompensationFailed)
        ->and($context->compensationError('b')?->getMessage())->toBe('cannot undo b')
        ->and($context->stepStatus('a'))->toBe(StepStatus::Compensated)
        ->and($context->compensationResult('a'))->toBe('undid a')
        ->and($events->hooks())->toBe(['onCompensationStarted', 'onStepCompensated', 'onStepCompensationFailed', 'onStepCompensated']);
});

it('stops at the first failure when the handler fails the saga', function (): void {
    $log = [];
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a'], 'c' => ['b']], ['b' => PHP_INT_MAX], $log);
    $context = orchestrationCompletedContext($saga, ['a', 'b', 'c']);

    expect(fn () => orchestrationCompensator(CompensationPolicy::StrictSequential, new FailFastCompensationErrorHandler)->compensate($saga, $context))
        ->toThrow(CompensationException::class, 'Saga [order] stopped compensating at step [b]: cannot undo b')
        ->and($log)->toBe(['c', 'b'])
        ->and($context->stepStatus('a'))->toBe(StepStatus::Done);
});

it('GROUPED_PARALLEL undoes layer by layer in reverse, declaration order inside a layer', function (): void {
    $log = [];
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a'], 'c' => ['a'], 'd' => ['b', 'c']], [], $log);

    orchestrationCompensator(CompensationPolicy::GroupedParallel, new DefaultCompensationErrorHandler)->compensate($saga, orchestrationCompletedContext($saga, ['a', 'c', 'b', 'd']));

    expect($log)->toBe(['d', 'b', 'c', 'a']);
});

it('RETRY_WITH_BACKOFF retries a compensation compensationRetry times', function (): void {
    $log = [];
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a']], ['b' => 2], $log, compensationRetry: 2);
    $context = orchestrationCompletedContext($saga, ['a', 'b']);

    orchestrationCompensator(CompensationPolicy::RetryWithBackoff, new DefaultCompensationErrorHandler)->compensate($saga, $context);

    expect($log)->toBe(['b', 'b', 'b', 'a'])
        ->and($context->stepStatus('b'))->toBe(StepStatus::Compensated)
        ->and($context->attempts('b:compensate'))->toBe(3);
});

it('lets the retry handler try a failed compensation again', function (): void {
    $log = [];
    $saga = orchestrationCompensationSaga(['a' => []], ['a' => 1], $log);
    $context = orchestrationCompletedContext($saga, ['a']);

    orchestrationCompensator(CompensationPolicy::StrictSequential, new RetryWithBackoffCompensationErrorHandler(3))->compensate($saga, $context);

    expect($log)->toBe(['a', 'a'])->and($context->stepStatus('a'))->toBe(StepStatus::Compensated);
});

it('lets a handler mark a failed compensation as compensated', function (): void {
    $log = [];
    $saga = orchestrationCompensationSaga(['a' => []], ['a' => PHP_INT_MAX], $log);
    $context = orchestrationCompletedContext($saga, ['a']);
    $handler = new class implements CompensationErrorHandler
    {
        public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
        {
            return CompensationErrorResult::MarkCompensated;
        }
    };

    orchestrationCompensator(CompensationPolicy::StrictSequential, $handler)->compensate($saga, $context);

    expect($context->stepStatus('a'))->toBe(StepStatus::Compensated);
});

it('CIRCUIT_BREAKER stops compensating after a critical step fails and leaves the rest for recovery', function (): void {
    $log = [];
    $logger = new RecordingLogger;
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a'], 'c' => ['b']], ['c' => PHP_INT_MAX], $log, critical: ['c']);
    $context = orchestrationCompletedContext($saga, ['a', 'b', 'c']);

    orchestrationCompensator(CompensationPolicy::CircuitBreaker, new DefaultCompensationErrorHandler, logger: $logger)->compensate($saga, $context);

    expect($log)->toBe(['c'])
        ->and($context->stepStatus('b'))->toBe(StepStatus::Done)
        ->and($context->stepStatus('a'))->toBe(StepStatus::Done)
        ->and(implode("\n", $logger->messages('warning')))->toContain('left [b, a] uncompensated');
});

it('CIRCUIT_BREAKER shares a firefly/resilience breaker per saga across executions', function (): void {
    $log = [];
    $resilience = ResilienceRegistry::fromConfig(new Config(new Repository(['firefly' => ['resilience' => ['circuit-breaker' => ['orchestration-compensation-order' => ['failure-threshold' => 1]]]]])), new InMemoryResilienceStore);
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a'], 'c' => ['b']], ['c' => PHP_INT_MAX], $log);
    $context = orchestrationCompletedContext($saga, ['a', 'b', 'c']);

    orchestrationCompensator(CompensationPolicy::CircuitBreaker, new DefaultCompensationErrorHandler, resilience: $resilience)->compensate($saga, $context);

    expect($log)->toBe(['c'])->and($context->stepStatus('b'))->toBe(StepStatus::Done);
});

it('BEST_EFFORT attempts every compensation even when the handler would fail the saga', function (): void {
    $log = [];
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a'], 'c' => ['b']], ['b' => PHP_INT_MAX, 'c' => PHP_INT_MAX], $log);
    $context = orchestrationCompletedContext($saga, ['a', 'b', 'c']);

    orchestrationCompensator(CompensationPolicy::BestEffort, new FailFastCompensationErrorHandler)->compensate($saga, $context);

    expect($log)->toBe(['c', 'b', 'a'])->and($context->stepStatus('a'))->toBe(StepStatus::Compensated);
});

it('skips steps that never completed and steps without a compensation', function (): void {
    $log = [];
    $saga = SagaBuilder::saga('order')
        ->step('a')->handler(static fn (): string => 'a')->add()
        ->step('b')->handler(static fn (): string => 'b')->compensation(static function () use (&$log): void {
            $log[] = 'b';
        })->add()
        ->build();
    $context = orchestrationCompletedContext($saga, ['a']);

    orchestrationCompensator(CompensationPolicy::StrictSequential, new DefaultCompensationErrorHandler)->compensate($saga, $context);

    expect($log)->toBe([])->and($context->stepStatus('a'))->toBe(StepStatus::Done);
});

it('records a compensation that hits a downstream open breaker as failed instead of escaping the sweep', function (): void {
    $log = [];
    $saga = SagaBuilder::saga('order')
        ->step('a')->handler(static fn (): string => 'a')->compensation(static function () use (&$log): string {
            $log[] = 'a';

            return 'undid a';
        })->add()
        ->step('b')->dependsOn('a')->handler(static fn (): string => 'b')->compensation(static function () use (&$log): never {
            $log[] = 'b';

            throw new CircuitBreakerOpenException;
        })->add()
        ->build();

    foreach ([CompensationPolicy::StrictSequential, CompensationPolicy::CircuitBreaker] as $policy) {
        $log = [];
        $context = orchestrationCompletedContext($saga, ['a', 'b']);

        orchestrationCompensator($policy, new DefaultCompensationErrorHandler)->compensate($saga, $context);

        expect($log)->toBe(['b', 'a'])
            ->and($context->stepStatus('b'))->toBe(StepStatus::CompensationFailed)
            ->and($context->compensationError('b'))->toBeInstanceOf(CircuitBreakerOpenException::class)
            ->and($context->stepStatus('a'))->toBe(StepStatus::Compensated);
    }
});

it('cuts a handler that keeps answering RETRY off after MAX_HANDLER_RETRIES retries', function (): void {
    $log = [];
    $logger = new RecordingLogger;
    $saga = orchestrationCompensationSaga(['a' => []], ['a' => PHP_INT_MAX], $log);
    $context = orchestrationCompletedContext($saga, ['a']);
    $handler = new class implements CompensationErrorHandler
    {
        public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
        {
            return CompensationErrorResult::Retry;
        }
    };

    orchestrationCompensator(CompensationPolicy::StrictSequential, $handler, logger: $logger)->compensate($saga, $context);

    expect($context->attempts('a:compensate'))->toBe(1 + SagaCompensator::MAX_HANDLER_RETRIES)
        ->and($context->stepStatus('a'))->toBe(StepStatus::CompensationFailed)
        ->and(implode("\n", $logger->messages('warning')))->toContain('after 25 handler retries');
});

it('CIRCUIT_BREAKER refuses the next execution of the same saga while its breaker is open', function (): void {
    $log = [];
    $logger = new RecordingLogger;
    $resilience = ResilienceRegistry::fromConfig(new Config(new Repository(['firefly' => ['resilience' => ['circuit-breaker' => ['orchestration-compensation-order' => ['failure-threshold' => 1]]]]])), new InMemoryResilienceStore);
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a']], ['b' => PHP_INT_MAX], $log);

    orchestrationCompensator(CompensationPolicy::CircuitBreaker, new DefaultCompensationErrorHandler, resilience: $resilience)->compensate($saga, orchestrationCompletedContext($saga, ['a', 'b']));
    $log = [];
    $second = orchestrationCompletedContext($saga, ['a', 'b']);
    orchestrationCompensator(CompensationPolicy::CircuitBreaker, new DefaultCompensationErrorHandler, logger: $logger, resilience: $resilience)->compensate($saga, $second);

    expect($log)->toBe([])
        ->and($second->stepStatus('b'))->toBe(StepStatus::Done)
        ->and($second->stepStatus('a'))->toBe(StepStatus::Done)
        ->and(implode("\n", $logger->messages('warning')))->toContain('left [b, a] uncompensated');
});

it('CIRCUIT_BREAKER runs the handler retries through the saga breaker and stops the sweep once they open it', function (): void {
    $log = [];
    $logger = new RecordingLogger;
    $resilience = ResilienceRegistry::fromConfig(new Config(new Repository(['firefly' => ['resilience' => ['circuit-breaker' => ['orchestration-compensation-order' => ['failure-threshold' => 2]]]]])), new InMemoryResilienceStore);
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a'], 'c' => ['b']], ['c' => PHP_INT_MAX], $log);
    $context = orchestrationCompletedContext($saga, ['a', 'b', 'c']);

    orchestrationCompensator(CompensationPolicy::CircuitBreaker, new RetryWithBackoffCompensationErrorHandler(3), logger: $logger, resilience: $resilience)->compensate($saga, $context);

    // The first try and ONE retry reach the breaker's threshold of two; the remaining two retries the handler
    // asked for never hit the failing dependency, and the sweep leaves b and a for recovery.
    expect($log)->toBe(['c', 'c'])
        ->and($context->attempts('c:compensate'))->toBe(2)
        ->and($resilience->circuitBreaker('orchestration-compensation-order')->state())->toBe('open')
        ->and($context->stepStatus('c'))->toBe(StepStatus::CompensationFailed)
        ->and($context->stepStatus('b'))->toBe(StepStatus::Done)
        ->and($context->stepStatus('a'))->toBe(StepStatus::Done)
        ->and(implode("\n", $logger->messages('warning')))->toContain('left [b, a] uncompensated');
});

it('CIRCUIT_BREAKER counts a handler retry that succeeds as a breaker success and carries on', function (): void {
    $log = [];
    $resilience = ResilienceRegistry::fromConfig(new Config(new Repository(['firefly' => ['resilience' => ['circuit-breaker' => ['orchestration-compensation-order' => ['failure-threshold' => 2]]]]])), new InMemoryResilienceStore);
    $saga = orchestrationCompensationSaga(['a' => [], 'b' => ['a']], ['b' => 1], $log);
    $context = orchestrationCompletedContext($saga, ['a', 'b']);

    orchestrationCompensator(CompensationPolicy::CircuitBreaker, new RetryWithBackoffCompensationErrorHandler(3), resilience: $resilience)->compensate($saga, $context);

    expect($log)->toBe(['b', 'b', 'a'])
        ->and($context->stepStatus('b'))->toBe(StepStatus::Compensated)
        ->and($context->stepStatus('a'))->toBe(StepStatus::Compensated)
        ->and($resilience->circuitBreaker('orchestration-compensation-order')->state())->toBe('closed');
});
