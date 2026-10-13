<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

/**
 * One member of a SagaComposition (jafly CompositionSaga): the saga it runs, under an alias other members
 * depend on and map data from. An `optional` member may fail — or fail to start at all: an unregistered saga,
 * an admission refusal, a condition that cannot be evaluated — without failing the composition; its dependents
 * are skipped and the reason lands in CompositionResult::$optionalFailures. A `condition` (ExpressionLanguage
 * over `input`, `results` by alias, `headers`) that evaluates false skips it.
 */
final readonly class CompositionSaga
{
    /**
     * @param  list<string>  $dependsOn
     * @param  array<string, mixed>  $staticInput
     */
    public function __construct(
        public string $alias,
        public string $sagaName,
        public array $dependsOn = [],
        public array $staticInput = [],
        public bool $optional = false,
        public string $condition = '',
    ) {}
}
