<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Firefly\FeatureFlags\Definition\Json;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\provider\ResolutionDetails;

/**
 * One evaluation, as the contract defines it. It carries the flag METADATA that open-feature/sdk 2.3's
 * ResolutionDetails has no slot for; toResolutionDetails() is the SDK's view of the same result.
 *
 * The metadata is keyed by array-key: PHP stores a numeric-looking name (`"2024"`) as an int key. Read a name
 * with `(string) $name`, and encode the map through Json::object(), which keeps a list-like map (`{"0": …}`) a
 * JSON object.
 *
 * `readonly` is shallow: the constructor retains its value, while DefaultFlagdEvaluator copies document variants.
 * toResolutionDetails() hands the SDK a converted copy.
 */
final readonly class Resolution
{
    /**
     * @param  array<array-key, bool|int|float|string>  $metadata
     */
    public function __construct(
        public mixed $value,
        public ?string $variant,
        public EvaluationReason $reason,
        public ?EvaluationError $error = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    /** The caller's default, no variant, reason ERROR and the code. */
    public static function error(mixed $default, EvaluationError $error, string $message): self
    {
        return new self($default, null, EvaluationReason::Error, $error, $message);
    }

    public function withValue(mixed $value): self
    {
        return new self($value, $this->variant, $this->reason, $this->error, $this->errorMessage, $this->metadata);
    }

    /** The SDK's view: plain PHP arrays (never stdClass), the reason and error code as OpenFeature strings. */
    public function toResolutionDetails(): ResolutionDetails
    {
        $value = Json::toPhp($this->value);
        $builder = (new ResolutionDetailsBuilder)
            ->withValue(is_bool($value) || is_string($value) || is_int($value) || is_float($value) || is_array($value) ? $value : null)
            ->withReason($this->reason->value);

        if ($this->variant !== null) {
            $builder->withVariant($this->variant);
        }

        if ($this->error !== null) {
            $builder->withError(new ResolutionError($this->error->toOpenFeature(), $this->errorMessage));
        }

        return $builder->build();
    }
}
