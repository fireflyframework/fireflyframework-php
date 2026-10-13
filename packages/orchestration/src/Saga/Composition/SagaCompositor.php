<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
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
 * prevent. A member whose own compensation throws is left out of `compensated` and the sweep moves on to the
 * next one; its execution stays COMPENSATING in the persistence port, where recovery finds it.
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
                    return $this->abort($composition, $correlationId, $results, $completed, $skipped, $error);
                }

                $results[$alias] = $result;

                if ($result->isSuccess()) {
                    $completed[] = $alias;

                    continue;
                }

                if ($member->optional) {
                    $unavailable[] = $alias;

                    continue;
                }

                return $this->abort($composition, $correlationId, $results, $completed, $skipped, $result->error ?? new OrchestrationException("Composition member [{$alias}] failed."));
            }
        }

        $this->events->onCompositionCompleted($composition->name, $correlationId, true);

        return new CompositionResult($composition->name, $correlationId, true, $results, null, [], $skipped);
    }

    /**
     * Compensates every completed member in reverse completion order and reports the composition as failed.
     *
     * @param  array<string, SagaResult>  $results
     * @param  list<string>  $completed
     * @param  list<string>  $skipped
     */
    private function abort(SagaComposition $composition, string $correlationId, array $results, array $completed, array $skipped, Throwable $error): CompositionResult
    {
        $compensated = [];
        foreach (array_reverse($completed) as $done) {
            try {
                $this->engine->compensate($results[$done]->correlationId);
                $compensated[] = $done;
            } catch (Throwable) {
                // Left COMPENSATING in the persistence port for recovery; the sweep continues.
            }
        }
        $this->events->onCompositionCompleted($composition->name, $correlationId, false);

        return new CompositionResult($composition->name, $correlationId, false, $results, $error, $compensated, $skipped);
    }
}
