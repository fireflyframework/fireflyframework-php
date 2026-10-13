<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Composition;

use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\Exception\CompensationException;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Event\OrchestrationEvents;
use Firefly\Orchestration\Saga\Composition\CompositionDataFlowManager;
use Firefly\Orchestration\Saga\Composition\SagaCompositor;
use Firefly\Orchestration\Settings\TccSettings;
use Firefly\Orchestration\Tcc\Engine\TccEngine;
use Firefly\Orchestration\Tcc\Engine\TccResult;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a TccComposition (jafly TccCompositor) behind `firefly.orchestration.tcc.composition.enabled`. Members
 * run layer by layer through TccEngine, so each is a normal, persisted, recoverable TCC execution carrying the
 * composition's correlation id in its headers. A member that ends CONFIRMED is final from its own point of
 * view, so when a later required member fails, each confirmed member is undone through
 * TccEngine::compensate() — its participants' cancel methods with their try results — in reverse completion
 * order (spec decision D14). Data flows from members' try results.
 *
 * Failure handling follows SagaCompositor: a required member "fails" when it does not confirm and equally
 * when it cannot run at all (its TCC is not registered, admission refuses it, its condition cannot be
 * evaluated); an optional member is tolerated either way and lands in TccCompositionResult::$optionalFailures,
 * its dependents skipped. The rollback sweep never stops at a member it cannot undo: it records it in
 * $compensationFailures and reports it through OrchestrationEvents::onStepCompensationFailed() (composition
 * name, its correlation id, the member's alias). A member whose compensate() was refused keeps its CONFIRMED
 * status; one whose cancel failed ends FAILED with the participant marked CANCEL_FAILED.
 */
final class TccCompositor
{
    public function __construct(
        private readonly TccEngine $engine,
        private readonly OrchestrationEvents $events,
        private readonly ConditionEvaluator $conditions,
        private readonly CompositionDataFlowManager $dataFlow,
        private readonly TccSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public function compose(TccComposition $composition, array $input = [], array $headers = []): TccCompositionResult
    {
        if (! $this->settings->compositionEnabled) {
            throw new PatternDisabledException(ExecutionPattern::Tcc, 'firefly.orchestration.tcc.composition.enabled');
        }

        foreach ($composition->members as $member) {
            if ($member->condition !== '') {
                $this->conditions->lint($member->condition, "TCC composition [{$composition->name}] member [{$member->alias}]");
            }
        }

        $correlationId = Str::uuid()->toString();
        $this->events->onCompositionStarted($composition->name, $correlationId);

        /** @var array<string, TccResult> $results */
        $results = [];
        $completed = [];
        $skipped = [];
        $unavailable = [];
        /** @var array<string, Throwable> $optionalFailures */
        $optionalFailures = [];

        foreach ($composition->layers as $layer) {
            foreach ($layer as $alias) {
                $member = $composition->members[$alias];
                $resultsByAlias = array_map(static fn (TccResult $result): array => $result->tryResults(), $results);

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
                        $member->tccName,
                        $this->dataFlow->resolveInputs($input, $member->staticInput, $composition->dataMappings, $alias, $resultsByAlias),
                        array_merge($headers, [SagaCompositor::COMPOSITION_HEADER => $correlationId, SagaCompositor::ALIAS_HEADER => $alias]),
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

                if ($result->isConfirmed()) {
                    $completed[] = $alias;

                    continue;
                }

                $error = $result->error ?? new OrchestrationException("TCC composition member [{$alias}] did not confirm.");
                if ($member->optional) {
                    $optionalFailures[$alias] = $error;
                    $unavailable[] = $alias;

                    continue;
                }

                return $this->abort($composition, $correlationId, $results, $completed, $skipped, $optionalFailures, $error);
            }
        }

        $this->events->onCompositionCompleted($composition->name, $correlationId, true);

        return new TccCompositionResult($composition->name, $correlationId, true, $results, null, [], $skipped, [], $optionalFailures);
    }

    /**
     * Cancels every confirmed member in reverse completion order and reports the composition as failed. A
     * member that cannot be fully cancelled is recorded and reported, and the sweep moves on to the next one.
     *
     * @param  array<string, TccResult>  $results
     * @param  list<string>  $completed
     * @param  list<string>  $skipped
     * @param  array<string, Throwable>  $optionalFailures
     */
    private function abort(TccComposition $composition, string $correlationId, array $results, array $completed, array $skipped, array $optionalFailures, Throwable $error): TccCompositionResult
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

        return new TccCompositionResult($composition->name, $correlationId, false, $results, $error, $compensated, $skipped, $failures, $optionalFailures);
    }

    /**
     * Cancels one confirmed member. Returns null when it ended CANCELED, and otherwise why it did not:
     * TccEngine::compensate() handles a failed cancel itself and returns a FAILED result.
     */
    private function undo(string $alias, TccResult $confirmed): ?Throwable
    {
        $undone = $this->engine->compensate($confirmed->correlationId);
        if ($undone->status === ExecutionStatus::Canceled) {
            return null;
        }

        $left = [];
        foreach ($undone->participants as $participantId => $outcome) {
            if ($outcome->status === StepStatus::CancelFailed) {
                $left[] = $participantId;
            }
        }
        $reason = $undone->report->failureReason ?? 'not canceled';

        return new CompensationException("TCC composition member [{$alias}] left [".implode(', ', $left)."] uncanceled: {$reason}");
    }
}
