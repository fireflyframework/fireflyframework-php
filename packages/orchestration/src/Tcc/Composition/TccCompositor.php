<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Composition;

use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Orchestration\Core\CompensationPolicy;
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
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Retry;
use Firefly\Resilience\Store\ResilienceStore;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a TccComposition (jafly TccCompositor) behind `firefly.orchestration.tcc.composition.enabled`. Members
 * run layer by layer through TccEngine, so each is a normal, persisted, recoverable TCC execution carrying the
 * composition's correlation id in its headers. A member that ends CONFIRMED is final from its own point of
 * view, so when a later required member fails, each confirmed member is undone through
 * TccEngine::compensate() — its participants' cancel methods with their try results — in the order the
 * compensation policy below sets (spec decision D14). Data flows from members' try results.
 *
 * Failure handling follows SagaCompositor: a required member "fails" when it does not confirm and equally
 * when it cannot run at all (its TCC is not registered, admission refuses it, its condition cannot be
 * evaluated); an optional member is tolerated either way and lands in TccCompositionResult::$optionalFailures,
 * its dependents skipped. A member the rollback cannot undo is recorded in $compensationFailures and reported
 * through OrchestrationEvents::onStepCompensationFailed() (composition name, its correlation id, the member's
 * alias). A member whose compensate() was refused keeps its CONFIRMED status; one whose cancel failed ends
 * FAILED with the participant marked CANCEL_FAILED.
 *
 * The rollback follows `firefly.orchestration.tcc.composition.compensation-policy`, with SagaCompensator's
 * meanings applied to members:
 *
 *  - STRICT_SEQUENTIAL: reverse completion order, one compensate() per member, the sweep moving on past a
 *    member it cannot undo. There is no compensation error handler between members to stop it, so
 *    BEST_EFFORT behaves exactly the same.
 *  - GROUPED_PARALLEL: reverse layer order, declaration order inside a layer (sequentially, as for sagas).
 *  - RETRY_WITH_BACKOFF: reverse completion order; a member whose cancel failed is compensated again through
 *    firefly/resilience's Retry (`firefly.resilience.retry.orchestration-tcc-composition-<composition>`, or
 *    three attempts backing off 100 ms then 200 ms) — the engine retries only its CANCEL_FAILED participants.
 *  - CIRCUIT_BREAKER: reverse completion order through a firefly/resilience breaker per composition
 *    (`firefly.resilience.circuit-breaker.orchestration-tcc-composition-<composition>`, or five failures and
 *    thirty seconds open); once it is open the sweep stops, and every member it did not cancel is recorded as
 *    a compensation failure.
 *  - BEST_EFFORT: reverse completion order, every member attempted.
 */
final class TccCompositor
{
    /** The firefly/resilience instance names this compositor looks up, followed by the composition's name. */
    public const string RESILIENCE_PREFIX = 'orchestration-tcc-composition-';

    public function __construct(
        private readonly TccEngine $engine,
        private readonly OrchestrationEvents $events,
        private readonly ConditionEvaluator $conditions,
        private readonly CompositionDataFlowManager $dataFlow,
        private readonly TccSettings $settings,
        private readonly ?ResilienceRegistry $resilience = null,
        private readonly ?ResilienceStore $store = null,
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
     * Cancels every confirmed member, in the order and with the failure handling that
     * `firefly.orchestration.tcc.composition.compensation-policy` names, and reports the composition as failed.
     * A member that cannot be fully cancelled is recorded and reported; only CIRCUIT_BREAKER stops the sweep,
     * and then every member it did not reach is recorded too, so `compensated` never claims a member that is
     * still CONFIRMED.
     *
     * @param  array<string, TccResult>  $results
     * @param  list<string>  $completed
     * @param  list<string>  $skipped
     * @param  array<string, Throwable>  $optionalFailures
     */
    private function abort(TccComposition $composition, string $correlationId, array $results, array $completed, array $skipped, array $optionalFailures, Throwable $error): TccCompositionResult
    {
        $policy = $this->settings->compositionCompensationPolicy;
        $breaker = $policy === CompensationPolicy::CircuitBreaker ? $this->breaker($composition->name) : null;
        $retry = $policy === CompensationPolicy::RetryWithBackoff ? $this->retry($composition->name) : null;
        $stoppedAt = null;
        $compensated = [];
        $failures = [];

        foreach ($this->rollbackOrder($composition, $completed, $policy) as $done) {
            if ($stoppedAt !== null) {
                $failure = new CompensationException("TCC composition [{$composition->name}] did not cancel member [{$done}]: its compensation circuit opened at member [{$stoppedAt}].");
            } else {
                try {
                    $failure = $this->attempt($done, $results[$done], $breaker, $retry);
                } catch (CircuitBreakerOpenException $open) {
                    $stoppedAt = $done;
                    $failure = new CompensationException("TCC composition [{$composition->name}] did not cancel member [{$done}]: its compensation circuit is open.", $open);
                }
                if ($failure !== null && $stoppedAt === null && $breaker !== null && $breaker->state() === 'open') {
                    $stoppedAt = $done;
                }
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
     * The members to cancel, in the policy's order. GROUPED_PARALLEL walks the composition's layers in reverse,
     * members of one layer in declaration order (sequentially: PHP runs one thing at a time, as for sagas);
     * every other policy cancels in reverse completion order.
     *
     * @param  list<string>  $completed
     * @return list<string>
     */
    private function rollbackOrder(TccComposition $composition, array $completed, CompensationPolicy $policy): array
    {
        if ($policy !== CompensationPolicy::GroupedParallel) {
            return array_reverse($completed);
        }

        $order = [];
        foreach (array_reverse($composition->layers) as $layer) {
            foreach ($layer as $alias) {
                if (in_array($alias, $completed, true)) {
                    $order[] = $alias;
                }
            }
        }

        return $order;
    }

    /**
     * Cancels one member under the policy: through `$retry` (RETRY_WITH_BACKOFF), which asks compensate() again
     * after a failed cancel — the engine then retries only the participants left CANCEL_FAILED — or through
     * `$breaker` (CIRCUIT_BREAKER), where every failed member counts towards the breaker's threshold. Returns
     * why the member is not cancelled, or null. Throws only when OUR breaker refused to admit the attempt: a
     * cancel that itself ran into some other open breaker failed like any other cancel.
     */
    private function attempt(string $alias, TccResult $confirmed, ?CircuitBreaker $breaker, ?Retry $retry): ?Throwable
    {
        $ran = false;
        $once = function () use ($alias, $confirmed, &$ran): null {
            $ran = true;
            $failure = $this->undo($alias, $confirmed);
            if ($failure !== null) {
                throw $failure;
            }

            return null;
        };

        try {
            if ($breaker !== null) {
                $breaker->call($once);
            } elseif ($retry !== null) {
                $retry->call($once);
            } else {
                $once();
            }
        } catch (CircuitBreakerOpenException $refused) {
            if (! $ran) {
                throw $refused;
            }

            return $refused;
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    /**
     * The composition's breaker: `firefly.resilience.circuit-breaker.orchestration-tcc-composition-<name>` when
     * configured (an invalid value in it throws its ConfigurationException here rather than being ignored),
     * otherwise a default breaker (five failures, thirty seconds open) on the resilience store. Without a
     * store there is no breaker and CIRCUIT_BREAKER cancels like STRICT_SEQUENTIAL.
     */
    private function breaker(string $composition): ?CircuitBreaker
    {
        $name = self::RESILIENCE_PREFIX.$composition;
        if ($this->resilience !== null && $this->resilience->hasCircuitBreaker($name)) {
            return $this->resilience->circuitBreaker($name);
        }

        return $this->store === null ? null : new CircuitBreaker(
            key: 'firefly:orchestration:tcc-composition:'.$composition,
            store: $this->store,
            failureThreshold: 5,
            waitDurationInOpen: 30.0,
        );
    }

    /**
     * The composition's retry: `firefly.resilience.retry.orchestration-tcc-composition-<name>` when configured,
     * otherwise three attempts backing off 100 ms then 200 ms.
     */
    private function retry(string $composition): Retry
    {
        $name = self::RESILIENCE_PREFIX.$composition;
        if ($this->resilience !== null && $this->resilience->hasRetry($name)) {
            return $this->resilience->retry($name);
        }

        return new Retry(maxAttempts: 3, waitDuration: 0.1, backoffMultiplier: 2.0);
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
