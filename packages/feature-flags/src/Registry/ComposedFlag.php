<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Registry;

use Firefly\FeatureFlags\Definition\FlagDefinition;

/** One key of a composition: the winning definition, where it came from and what it shadows. */
final readonly class ComposedFlag
{
    /**
     * @param  list<string>  $overrides  the lower layers that also define the key, lowest first
     * @param  list<array{source: string, definition: FlagDefinition}>  $layers  every layer defining the key, lowest first
     */
    public function __construct(
        public FlagDefinition $definition,
        public string $origin,
        public array $overrides,
        public array $layers,
    ) {}
}
