<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\Exception\CompensationException;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Event\OrchestrationEvents;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Orchestration\Saga\Engine\SagaResult;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a SagaComposition (jafly SagaCompositor). Members run layer by layer through SagaEngine, so each is a
 * normal, persisted, recoverable saga execution carrying the composition's correlation id in its headers.
 * When a required member fails, every member that COMPLETED is compensated through SagaEngine::compensate(),
 * in reverse completion order (spec decision D14 — jafly only marks them COMPENSATING), and ends CANCELLED.
 *
 * A required member "fails" when its saga reports a failure, and equally when it cannot run at all — its saga
 * is not registered, admission refuses it, or its condition cannot be evaluated: either way the members before
 * it already did their work, and leaving them uncompensated would be the one outcome a composition exists to
 * prevent. An optional member is tolerated either way: a failed result or a refusal to start lands in
 * CompositionResult::$optionalFailures, its dependents are skipped, and the composition carries on.
 *
 * The rollback sweep never stops at a member it cannot undo; it records it in CompositionResult::
 * $compensationFailures and reports it through OrchestrationEvents::onStepCompensationFailed() (the composition
 * name, its correlation id and the member's alias). Only a member whose every completed, compensable step was
 * undone is listed in `compensated`. What an uncompensated member's execution looks like depends on where the
 * undo stopped:
 *  - compensate() refused before it started (the execution lock timed out, the store could not be read, the
 *    execution was already cancelled): the execution keeps its status — a COMPLETED member stays COMPLETED,
 *    which recovery never picks up, so the result is the only place the caller learns of it;
 *  - one of its compensations failed and the engine handled it (the error handler continued, FAIL_SAGA, an
 *    open compensation circuit): the execution ends CANCELLED with the failed steps marked in its report;
 *  - compensate() threw after it started: the execution stays COMPENSATING, where recovery finds it.
 */
final class SagaCompositor
{
    public const string COMPOSITION_HEADER = 'x-firefly-composition';

    public const string ALIAS_HEADER = 'x-firefly-composition-alias';

    public function __construct(
        private readonly SagaEngine $engine,
        private readonly OrchestrationEvents $events,
        private readonly ConditionEvaluator $conditions,
        private readonly CompositionDataFlowManager $dataFlow,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public function compose(SagaComposition $composition, array $input = [], array $headers = []): CompositionResult
    {
        foreach ($composition->sagas as $member) {
            if ($member->condition !== '') {
                $this->conditions->lint($member->condition, "Saga composition [{$composition->name}] member [{$member->alias}]");
            }
        }

        $correlationId = Str::uuid()->toString();
        $this->events->onCompositionStarted($composition->name, $correlationId);

        /** @var array<string, SagaResult> $results */
        $results = [];
        $completed = [];
        $skipped = [];
        $unavailable = [];
        /** @var array<string, Throwable> $optionalFailures */
        $optionalFailures = [];

        foreach ($composition->layers as $layer) {
            foreach ($layer as $alias) {
                $member = $composition->sagas[$alias];
                $resultsByAlias = array_map(static fn (SagaResult $result): array => $result->stepResults(), $results);

                try {
                    if (array_intersect($member->dependsOn, $unavailable) !== []
                        || ($member->condition !== '' && ! $this->conditions->evaluate($member->condition, ['input' => $input, 'results' => $resultsByAlias, 'variables' => [], 'headers' => $headers]))) {
                        $skipped[] = $alias;
                        $unavailable[] = $alias;

                        continue;
                    }

                    // The composition's own headers win over a caller's header of the same name: a member must
                    // never claim to belong to a composition it is not part of.
                    $result = $this->engine->execute(
                        $member->sagaName,
                        $this->dataFlow->resolveInputs($input, $member->staticInput, $composition->dataMappings, $alias, $resultsByAlias),
                        array_merge($headers, [self::COMPOSITION_HEADER => $correlationId, self::ALIAS_HEADER => $alias]),
                    );
                } catch (Throwable $error) {
                    if ($member->optional) {
                        $optionalFailures[$alias] = $error;
                        $unavailable[] = $alias;

                        continue;
                    }

                    return $this->abort($composition, $correlationId, $results, $completed, $skipped, $optionalFailures, $error);
                }

                $results[$alias] = $result;

                if ($result->isSuccess()) {
                    $completed[] = $alias;

                    continue;
                }

                $error = $result->error ?? new OrchestrationException("Composition member [{$alias}] failed.");
                if ($member->optional) {
                    $optionalFailures[$alias] = $error;
                    $unavailable[] = $alias;

                    continue;
                }

                return $this->abort($composition, $correlationId, $results, $completed, $skipped, $optionalFailures, $error);
            }
        }

        $this->events->onCompositionCompleted($composition->name, $correlationId, true);

        return new CompositionResult($composition->name, $correlationId, true, $results, null, [], $skipped, [], $optionalFailures);
    }

    /**
     * Compensates every completed member in reverse completion order and reports the composition as failed.
     * A member that cannot be fully undone is recorded and reported, and the sweep moves on to the next one.
     *
     * @param  array<string, SagaResult>  $results
     * @param  list<string>  $completed
     * @param  list<string>  $skipped
     * @param  array<string, Throwable>  $optionalFailures
     */
    private function abort(SagaComposition $composition, string $correlationId, array $results, array $completed, array $skipped, array $optionalFailures, Throwable $error): CompositionResult
    {
        $compensated = [];
        $failures = [];
        foreach (array_reverse($completed) as $done) {
            try {
                $failure = $this->undo($done, $results[$done]);
            } catch (Throwable $refused) {
                $failure = $refused;
            }

            if ($failure === null) {
                $compensated[] = $done;

                continue;
            }

            $failures[$done] = $failure;
            $this->events->onStepCompensationFailed($composition->name, $correlationId, $done, $failure);
        }
        $this->events->onCompositionCompleted($composition->name, $correlationId, false);

        return new CompositionResult($composition->name, $correlationId, false, $results, $error, $compensated, $skipped, $failures, $optionalFailures);
    }

    /**
     * Compensates one completed member. Returns null when every step it completed that has a compensation was
     * undone, and otherwise the reason it was not: SagaEngine::compensate() handles a failed compensation
     * itself and still returns a CANCELLED result, so the outcome of each step is what tells the two apart.
     */
    private function undo(string $alias, SagaResult $completed): ?Throwable
    {
        $undone = $this->engine->compensate($completed->correlationId);
        $definition = $this->engine->registry()->get($completed->sagaName);

        $left = [];
        $cause = null;
        foreach ($completed->steps as $stepId => $outcome) {
            if ($outcome->status !== StepStatus::Done || ($definition->steps[$stepId] ?? null)?->compensation === null) {
                continue;
            }

            $after = $undone->steps[$stepId] ?? null;
            if ($after?->compensated !== true) {
                $left[] = $stepId;
                $cause ??= $after?->compensationError;
            }
        }

        if ($left === []) {
            return null;
        }

        $reason = $cause?->getMessage() ?? $undone->report->failureReason ?? 'not compensated';

        return new CompensationException("Composition member [{$alias}] left [".implode(', ', $left)."] uncompensated: {$reason}", $cause);
    }
}
