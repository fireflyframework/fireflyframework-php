<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

/** Copies `sourceField` of member `sourceSaga`'s results into input field `targetField` of member `targetSaga` (aliases). */
final readonly class DataMapping
{
    public function __construct(
        public string $sourceSaga,
        public string $sourceField,
        public string $targetSaga,
        public string $targetField,
    ) {}
}
