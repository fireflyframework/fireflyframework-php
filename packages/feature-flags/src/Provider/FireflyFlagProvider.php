<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Provider;

use Firefly\FeatureFlags\Evaluation\EvaluationError;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Evaluation\Resolution;
use Firefly\FeatureFlags\Registry\FlagDocumentSource;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ResolutionDetails;
use Throwable;

/**
 * The OpenFeature provider over the composed flag document. It never throws: a document that cannot be read, or an
 * evaluator that fails, is a GENERAL error carrying the caller's default, like every other evaluation failure
 * (logged at DEBUG).
 *
 * Values never leave it shared with the document. An object variant is (or holds) stdClass instances that every
 * reader of the document gets, so resolution() hands out a deep copy (ValueCopy) and the SDK's ResolutionDetails
 * hold plain arrays (Resolution::toResolutionDetails()): a caller mutating what it got cannot change the flag.
 *
 * The context reaches the evaluator as it is: its targeting key, and its attributes unconverted (the evaluator turns
 * a date-time into epoch milliseconds, CONTRACT.md "Evaluation context").
 */
final class FireflyFlagProvider extends AbstractProvider
{
    public const string NAME = 'firefly';

    protected static string $NAME = self::NAME;

    public function __construct(
        private readonly FlagDocumentSource $documents,
        private readonly FlagdEvaluator $evaluator,
    ) {}

    public function resolveBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->evaluate($flagKey, FlagType::Boolean, $defaultValue, $context)->toResolutionDetails();
    }

    public function resolveStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->evaluate($flagKey, FlagType::String, $defaultValue, $context)->toResolutionDetails();
    }

    public function resolveIntegerValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->evaluate($flagKey, FlagType::Integer, $defaultValue, $context)->toResolutionDetails();
    }

    public function resolveFloatValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->evaluate($flagKey, FlagType::Float, $defaultValue, $context)->toResolutionDetails();
    }

    /**
     * @param  mixed[]  $defaultValue
     */
    public function resolveObjectValue(string $flagKey, array $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->evaluate($flagKey, FlagType::Object, $defaultValue, $context)->toResolutionDetails();
    }

    /**
     * The Firefly view of one evaluation, metadata included (what the SDK's ResolutionDetails cannot carry), with a
     * value that is the caller's own copy. No OpenFeature client is involved, so no hook runs: an evaluation made
     * here records no metric and no exposure event.
     */
    public function resolution(string $flagKey, FlagType $type, mixed $default, ?EvaluationContext $context = null): Resolution
    {
        $resolution = $this->evaluate($flagKey, $type, $default, $context);

        return $resolution->withValue(ValueCopy::of($resolution->value));
    }

    /** The evaluator's answer; callers defensively copy or convert values from custom evaluators too. */
    private function evaluate(string $flagKey, FlagType $type, mixed $default, ?EvaluationContext $context): Resolution
    {
        $resolution = $this->attempt($flagKey, $type, $default, $context);

        if ($resolution->error !== null) {
            $this->logger?->debug('Feature flag [{flag}] evaluated with {error}: {message}', [
                'flag' => $flagKey,
                'error' => $resolution->error->value,
                'message' => $resolution->errorMessage,
            ]);
        }

        return $resolution;
    }

    private function attempt(string $flagKey, FlagType $type, mixed $default, ?EvaluationContext $context): Resolution
    {
        try {
            $document = $this->documents->document();
        } catch (Throwable $failure) {
            return Resolution::error($default, EvaluationError::General, 'The flag set could not be read: '.$failure->getMessage());
        }

        try {
            return $this->evaluator->evaluate(
                $document,
                $flagKey,
                $type,
                $default,
                $context?->getTargetingKey(),
                $context?->getAttributes()->toArray() ?? [],
            );
        } catch (Throwable $failure) {
            return Resolution::error($default, EvaluationError::General, "Evaluating flag [{$flagKey}] failed: {$failure->getMessage()}");
        }
    }
}
