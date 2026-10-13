<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Firefly\Orchestration\Event\OrchestrationTracing;
use Firefly\Resilience\Retry;
use Firefly\Resilience\TimeLimiter;

/**
 * Runs a step with its StepOptions on firefly/resilience's own primitives rather than a second retry loop:
 * `retry` extra attempts through Retry (a fixed `backoffMs` wait, plus up to `jitterFactor` of it at random
 * when `jitter` is on) and `timeoutMs` through TimeLimiter — which, as everywhere in the framework, interrupts a
 * blocking call only under pcntl for a whole-second timeout and otherwise measures after the call returns
 * (docs/modules/resilience.md). Every attempt is recorded on the context and wrapped in a step span.
 */
final class StepInvoker
{
    public function __construct(
        private readonly InvocationRunner $runner,
        private readonly OrchestrationTracing $tracing,
    ) {}

    public function invoke(string $executionName, string $stepId, Invocation $invocation, StepOptions $options, ExecutionContext $context, mixed $input, mixed $implicit): mixed
    {
        $attempt = function () use ($executionName, $stepId, $invocation, $options, $context, $input, $implicit): mixed {
            $context->recordAttempt($stepId);

            return $this->tracing->traceStep($executionName, $stepId, $context->correlationId, function () use ($invocation, $options, $context, $input, $implicit): mixed {
                $call = fn (): mixed => $this->runner->run($invocation, $context, $input, $implicit);

                return $options->timeoutMs > 0 ? (new TimeLimiter($options->timeoutMs / 1000))->call($call) : $call();
            });
        };

        if ($options->retry <= 0) {
            return $attempt();
        }

        $wait = $options->backoffMs / 1000;

        return (new Retry(
            maxAttempts: $options->retry + 1,
            waitDuration: $wait,
            jitter: $options->jitter ? $wait * $options->jitterFactor : 0.0,
        ))->call($attempt);
    }
}
