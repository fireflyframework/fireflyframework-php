<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\Resolution;
use Firefly\FeatureFlags\Provider\ValueCopy;
use OpenFeature\interfaces\flags\EvaluationDetails;
use stdClass;

/**
 * One evaluation with the flag metadata open-feature/sdk 2.3's EvaluationDetails cannot carry.
 *
 * The factories copy the value deeply (ValueCopy), so an evaluation never shares an instance with the stored
 * definition. The metadata is keyed by array-key: PHP stores a numeric-looking name (`"2024"`) as an int key, and
 * toArray() encodes the map through Json::object(), so such names stay JSON object members.
 */
final readonly class FlagEvaluation
{
    /**
     * @param  array<array-key, bool|int|float|string>  $metadata
     */
    public function __construct(
        public string $key,
        public mixed $value,
        public ?string $variant,
        public string $reason,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    /**
     * An evaluation made through an OpenFeature client; $metadata applies only when it did not fail.
     *
     * @param  array<array-key, bool|int|float|string>  $metadata
     */
    public static function fromDetails(string $key, EvaluationDetails $details, array $metadata): self
    {
        $error = $details->getError();

        return new self(
            $key,
            ValueCopy::of($details->getValue()),
            $error === null ? $details->getVariant() : null,
            $error === null ? ($details->getReason() ?? 'UNKNOWN') : 'ERROR',
            $error?->getResolutionErrorCode()->getValue(),
            $error?->getResolutionErrorMessage(),
            $error === null ? $metadata : [],
        );
    }

    /** An evaluation made by FireflyFlagProvider::resolution() (or an evaluator), metadata included. */
    public static function fromResolution(string $key, Resolution $resolution): self
    {
        return new self(
            $key,
            ValueCopy::of($resolution->value),
            $resolution->variant,
            $resolution->reason->value,
            $resolution->error?->value,
            $resolution->errorMessage,
            $resolution->metadata,
        );
    }

    /**
     * The contract's `evaluate` body (CONTRACT.md "The `flags` actuator endpoint"); the metadata is always a JSON
     * object, `{}` when there is none.
     *
     * @return array{key: string, value: mixed, variant: ?string, reason: string, errorCode: ?string, metadata: stdClass|array<array-key, bool|int|float|string>}
     */
    public function toArray(): array
    {
        /** @var stdClass|array<array-key, bool|int|float|string> $metadata */
        $metadata = Json::object($this->metadata);

        return [
            'key' => $this->key,
            'value' => $this->value,
            'variant' => $this->variant,
            'reason' => $this->reason,
            'errorCode' => $this->errorCode,
            'metadata' => $metadata,
        ];
    }
}
