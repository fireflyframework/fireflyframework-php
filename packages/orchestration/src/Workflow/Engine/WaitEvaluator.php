<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Engine;

use DateTimeImmutable;
use Firefly\Orchestration\Core\Exception\SignalTimeoutException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Event\OrchestrationEvents;
use Firefly\Orchestration\Workflow\Registry\SignalWait;
use Firefly\Orchestration\Workflow\Registry\TimerWait;
use Firefly\Orchestration\Workflow\Registry\WaitDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowStepDefinition;

/**
 * Decides whether a waiting step may run now. On first sight it SCHEDULES the step's timers (and one timeout
 * timer per signal with a timeoutMs) on the context, so they are checkpointed and the tick finds them through
 * `wakeAt`. A timer stays on the context until its wait is satisfied, so a WaitForAll that already saw its
 * timer fire still knows it did when the last signal arrives.
 *
 * SIGNAL: the signal was delivered (now or earlier — signals are buffered); TIMER: every timer fired; ALL: all
 * signals delivered and all timers fired; ANY: one of them, named in the variable `wait.trigger`. Consumed
 * signals become the variables `signals.<name>`. A signal whose timeout timer fired first throws
 * SignalTimeoutException, which fails the step.
 */
final class WaitEvaluator
{
    public function __construct(private readonly OrchestrationEvents $events) {}

    public static function timerId(string $stepId, TimerWait $timer, int $index): string
    {
        return $timer->id !== '' ? $timer->id : "{$stepId}:timer:{$index}";
    }

    public function satisfied(string $workflowId, WorkflowStepDefinition $step, ExecutionContext $context, DateTimeImmutable $now): bool
    {
        $wait = $step->wait;
        if ($wait === null) {
            return true;
        }

        $timerIds = [];
        foreach ($wait->timers as $index => $timer) {
            $id = self::timerId($step->id, $timer, $index);
            $timerIds[] = $id;
            if (! $context->hasTimer($id)) {
                $context->scheduleTimer($id, $now->modify(sprintf('+%d milliseconds', $timer->delayMs)));
            }
        }
        foreach ($wait->signals as $signal) {
            $timeoutId = "{$step->id}:signal-timeout:{$signal->name}";
            if ($signal->timeoutMs > 0 && ! $context->hasSignal($signal->name) && ! $context->hasTimer($timeoutId)) {
                $context->scheduleTimer($timeoutId, $now->modify(sprintf('+%d milliseconds', $signal->timeoutMs)));
            }
        }

        $delivered = array_values(array_filter($wait->signals, static fn (SignalWait $signal): bool => $context->hasSignal($signal->name)));
        $fired = array_values(array_filter($timerIds, static fn (string $id): bool => $context->timerFired($id, $now)));

        $satisfied = match ($wait->kind) {
            WaitDefinition::SIGNAL, WaitDefinition::ALL => count($delivered) === count($wait->signals) && count($fired) === count($timerIds),
            WaitDefinition::TIMER => count($fired) === count($timerIds),
            default => $delivered !== [] || $fired !== [],
        };

        if (! $satisfied) {
            foreach ($wait->signals as $signal) {
                $timeoutId = "{$step->id}:signal-timeout:{$signal->name}";
                if (! $context->hasSignal($signal->name) && $context->timerFired($timeoutId, $now)) {
                    $context->removeTimer($timeoutId);

                    throw new SignalTimeoutException("Workflow [{$workflowId}] step [{$step->id}] waited longer than {$signal->timeoutMs} ms for signal [{$signal->name}].");
                }
            }

            return false;
        }

        if ($wait->kind === WaitDefinition::ANY) {
            $trigger = $delivered !== [] ? $delivered[0]->name : $fired[0];
            $context->setVariable('wait.trigger', $trigger);
            if ($delivered !== []) {
                $context->setVariable('signals.'.$delivered[0]->name, $context->consumeSignal($delivered[0]->name));
            }
        } else {
            foreach ($delivered as $signal) {
                $context->setVariable('signals.'.$signal->name, $context->consumeSignal($signal->name));
            }
        }

        foreach ($fired as $id) {
            $this->events->onTimerFired($workflowId, $context->correlationId, $id);
        }
        foreach ($timerIds as $id) {
            $context->removeTimer($id);
        }
        foreach ($wait->signals as $signal) {
            $context->removeTimer("{$step->id}:signal-timeout:{$signal->name}");
        }

        return true;
    }
}
