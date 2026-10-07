<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Firefly\FeatureFlags\Definition\FlagDocument;

/**
 * flagd's in-process evaluation (spec §4.3) over one document. Implementations never throw: every failure is a
 * Resolution carrying the caller's default and an error code.
 */
interface FlagdEvaluator
{
    /**
     * @param  array<array-key, mixed>  $attributes  the evaluation-context attributes (targetingKey excluded)
     */
    public function evaluate(
        FlagDocument $document,
        string $flagKey,
        FlagType $type,
        mixed $default,
        ?string $targetingKey = null,
        array $attributes = [],
    ): Resolution;
}
