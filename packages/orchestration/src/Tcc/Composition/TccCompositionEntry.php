<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Composition;

/** One member of a TccComposition: the TCC it runs under an alias, as CompositionSaga is for sagas. */
final readonly class TccCompositionEntry
{
    /**
     * @param  list<string>  $dependsOn
     * @param  array<string, mixed>  $staticInput
     */
    public function __construct(
        public string $alias,
        public string $tccName,
        public array $dependsOn = [],
        public array $staticInput = [],
        public bool $optional = false,
        public string $condition = '',
    ) {}
}
