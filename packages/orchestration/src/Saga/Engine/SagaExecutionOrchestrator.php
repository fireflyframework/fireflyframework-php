<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Engine;

use DateTimeImmutable;
use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Firefly\Orchestration\Core\EngineSupport;
use Firefly\Orchestration\Core\Exception\StepTimeoutException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepInvoker;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Saga\Registry\SagaStepDefinition;
use Throwable;

/**
 * Runs a saga's steps forward (jafly SagaExecutionOrchestrator): layer by layer, one step after another in
 * declaration order (spec decision D2). A SETTLED step is skipped, which is what makes resume() continue a
 * crashed execution instead of repeating it. Each step is checkpointed RUNNING before it is invoked — a crash
 * mid-step leaves that step visibly RUNNING on disk — and checkpointed again with its result.
 */
final class SagaExecutionOrchestrator
{
    public function __construct(
        private readonly StepInvoker $invoker,
        private readonly EngineSupport $support,
    ) {}

    public function orchestrate(SagaDefinition $saga, StepInputs $inputs, ExecutionContext $context, ?DateTimeImmutable $deadline): bool
    {
        $context->setTopologyLayers($saga->layers);

        foreach ($saga->layers as $layer) {
            foreach ($layer as $stepId) {
                if ($context->stepStatus($stepId)->isSettled()) {
                    continue;
                }
                if (! $this->runStep($saga, $saga->steps[$stepId], $inputs, $context, $deadline)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * {correlationId}, {input.<key>} and {header.<name>} are replaced; anything else is kept literally.
     */
    public static function interpolate(string $template, ExecutionContext $context, mixed $input): string
    {
        return (string) preg_replace_callback('/\{(correlationId|input\.[^}]+|header\.[^}]+)\}/', static function (array $match) use ($context, $input): string {
            $token = $match[1];
            if ($token === 'correlationId') {
                return $context->correlationId;
            }
            if (str_starts_with($token, 'input.')) {
                $value = is_array($input) ? ($input[substr($token, 6)] ?? '') : '';

                return is_scalar($value) ? (string) $value : '';
            }

            return $context->header(substr($token, 7)) ?? '';
        }, $template);
    }

    /** A step-event key names an input key whose value is the event key; without such a key it is used literally. */
    public static function eventKey(string $key, mixed $input): string
    {
        if ($key === '') {
            return '';
        }
        $value = is_array($input) ? ($input[$key] ?? null) : null;

        return is_scalar($value) ? (string) $value : $key;
    }

    private function runStep(SagaDefinition $saga, SagaStepDefinition $step, StepInputs $inputs, ExecutionContext $context, ?DateTimeImmutable $deadline): bool
    {
        $stepId = $step->id;
        $now = $this->support->now();

        if ($deadline !== null && $now > $deadline) {
            return $this->failed($saga, $context, $stepId, new StepTimeoutException("Saga [{$saga->name}] passed its deadline (firefly.orchestration.saga.default-timeout) before step [{$stepId}]."));
        }

        $input = $inputs->resolveFor($stepId, $context);
        $key = $step->options->idempotencyKey === '' ? '' : self::interpolate($step->options->idempotencyKey, $context, $input);
        if ($key !== '' && $context->hasIdempotencyKey($key)) {
            $context->setStepStatus($stepId, StepStatus::Skipped);
            $this->support->events->onStepSkippedIdempotent($saga->name, $context->correlationId, $stepId);

            return true;
        }

        $context->putStepInput($stepId, $input);
        $context->setStepStatus($stepId, StepStatus::Running);
        $context->markStepStarted($stepId, $now);
        $context->clearStepError($stepId);
        $this->support->events->onStepStarted($saga->name, $context->correlationId, $stepId);
        $this->support->checkpoint($context, ExecutionStatus::Running);

        $started = hrtime(true);
        try {
            $result = $this->invoker->invoke($saga->name, $stepId, $step->invocation, $step->options, $context, $input, $input);
        } catch (Throwable $error) {
            $context->setLatencyMs($stepId, self::since($started));

            return $this->failed($saga, $context, $stepId, $error);
        }

        $latency = self::since($started);
        $context->putResult($stepId, $result);
        $context->setLatencyMs($stepId, $latency);
        $context->setStepStatus($stepId, StepStatus::Done);
        $context->markCompleted($stepId);
        if ($key !== '') {
            $context->addIdempotencyKey($key);
        }
        $this->support->events->onStepSuccess($saga->name, $context->correlationId, $stepId, $context->attempts($stepId), $latency);

        $completed = OrchestrationEvent::stepCompleted($saga->name, $context->correlationId, ExecutionPattern::Saga, $stepId, $result, $this->support->now());
        if ($step->event !== null) {
            $this->support->publish($this->support->stepEvents, $completed->withTopic($step->event->topic)->withType($step->event->type)->withKey(self::eventKey($step->event->key, $input)));
        }
        $this->support->publish($this->support->lifecycle, $completed);
        $this->support->checkpoint($context, ExecutionStatus::Running);

        return true;
    }

    private function failed(SagaDefinition $saga, ExecutionContext $context, string $stepId, Throwable $error): bool
    {
        $context->setStepStatus($stepId, $error instanceof TimeoutException ? StepStatus::TimedOut : StepStatus::Failed);
        $context->putStepError($stepId, $error);
        $this->support->events->onStepFailed($saga->name, $context->correlationId, $stepId, $error, $context->attempts($stepId));
        $this->support->publish($this->support->lifecycle, OrchestrationEvent::stepFailed($saga->name, $context->correlationId, ExecutionPattern::Saga, $stepId, $error, $this->support->now()));

        return false;
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
