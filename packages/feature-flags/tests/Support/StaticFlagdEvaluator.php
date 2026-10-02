<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Evaluation\EvaluationError;
use Firefly\FeatureFlags\Evaluation\EvaluationReason;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Evaluation\Resolution;

/**
 * Lane L1b's evaluator until T12 merges DefaultFlagdEvaluator: flags WITHOUT targeting only. For valid flags without
 * targeting it answers the same value, variant and reason as the real evaluator (targeting is ignored here). It
 * records the context of its last call, so a test can see what an evaluation was given.
 */
final class StaticFlagdEvaluator implements FlagdEvaluator
{
    public ?string $lastTargetingKey = null;

    /** @var array<array-key, mixed> */
    public array $lastAttributes = [];

    public int $calls = 0;

    public function evaluate(FlagDocument $document, string $flagKey, FlagType $type, mixed $default, ?string $targetingKey = null, array $attributes = []): Resolution
    {
        $this->calls++;
        $this->lastTargetingKey = $targetingKey;
        $this->lastAttributes = $attributes;

        $flag = $document->flag($flagKey);
        if ($flag === null) {
            return Resolution::error($default, EvaluationError::FlagNotFound, "Flag [{$flagKey}] is not defined.");
        }

        $documentMetadata = array_filter($document->metadata, static fn (mixed $value): bool => is_bool($value) || is_int($value) || is_float($value) || is_string($value));
        $metadata = array_replace($documentMetadata, $flag->metadata());
        if ($flag->isDisabled()) {
            return new Resolution($default, null, EvaluationReason::Disabled, metadata: $metadata);
        }

        $variant = $flag->defaultVariant();
        if ($variant === null) {
            return new Resolution($default, null, EvaluationReason::Default, metadata: $metadata);
        }

        if (! $flag->hasVariant($variant)) {
            return Resolution::error($default, EvaluationError::General, "Flag [{$flagKey}] names default variant [{$variant}], which is not one of its variants.");
        }

        $value = $flag->variantValue($variant);
        if (! $type->accepts($value)) {
            return Resolution::error($default, EvaluationError::TypeMismatch, 'type mismatch');
        }

        return new Resolution($type === FlagType::Float && is_int($value) ? (float) $value : $value, $variant, EvaluationReason::Static, metadata: $metadata);
    }
}
