<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Closure;
use DateTimeInterface;
use Firefly\FeatureFlags\Definition\FlagDefinition;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use LogicException;
use ReflectionReference;
use stdClass;
use Throwable;

/**
 * flagd's in-process evaluator (openfeature-flagd-core 1.0.0, FlagdCore._resolve), in PHP:
 *
 *   unknown key                      → caller default, ERROR / FLAG_NOT_FOUND
 *   state DISABLED                   → caller default, DISABLED (no type check)
 *   no targeting                     → defaultVariant, STATIC   (no defaultVariant → caller default, DEFAULT)
 *   targeting → null                 → defaultVariant, DEFAULT  (no defaultVariant → caller default, DEFAULT)
 *   targeting → a variant name       → that variant, TARGETING_MATCH
 *   targeting → anything else        → caller default, ERROR / GENERAL (a name no variant has, or one whose value
 *                                      is null; a defaultVariant naming no variant is GENERAL too)
 *   targeting not an object          → caller default, ERROR / PARSE_ERROR (one Python reads as false — false, 0,
 *                                      "" — is no targeting at all)
 *   an unknown operation, a `$ref`   → caller default, ERROR / PARSE_ERROR
 *   left unresolved, or a targeting
 *   past RefResolver's limits
 *   any other failure of the rule    → caller default, ERROR / GENERAL
 *   a value of another type          → caller default, ERROR / TYPE_MISMATCH (not checked for DISABLED, nor for a
 *                                      DEFAULT without a variant)
 *
 * Types are flagd's: a float request accepts an integer and a boolean (Python's bool is an int) and returns either
 * as a float — the caller's default too, whenever the result is not an error; an object request accepts an object
 * or a list. A targeting result names its variant the way the reference turns it into a key: a boolean is "true"
 * or "false", text is itself, anything else is Python's str() of it (PythonText), so a `fractional` bucket written
 * as 1 selects the variant "1" and one written as 2.0 the variant "2.0".
 *
 * Two departures from the reference, both contract rulings: `$ref`s resolve structurally and transitively within
 * RefResolver's limits (flagd substitutes them textually), and a defaultVariant "" names the variant "" when the
 * flag has one (flagd reads "" as no default variant).
 *
 * The JSON Logic data is the context attributes, then `$flagd` {flagKey, timestamp (Unix seconds)} and
 * `targetingKey`, which replace any attribute of those names. Every DateTimeInterface in the attributes, at any
 * depth, is evaluated as its Unix epoch time in milliseconds (CONTRACT.md "Evaluation context"); the caller's
 * attributes are never modified (a container holding a date-time is copied, one object copied once wherever it
 * appears, so an object that contains itself still does; arrays are values, read wherever they appear).
 * Successful results carry the document's scalar metadata with the flag's merged over it; errors carry none.
 * evaluate() never throws: every failure is a Resolution.
 */
final class DefaultFlagdEvaluator implements FlagdEvaluator
{
    private readonly JsonLogic $logic;

    /**
     * @param  (Closure(): int)|null  $clock  Unix seconds for `$flagd.timestamp`
     */
    public function __construct(private readonly ?Closure $clock = null)
    {
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('Feature flag bucketing needs 64-bit integers: (hash * totalWeight) >> 32 overflows a 32-bit PHP build.');
        }

        $this->logic = FlagdOperators::jsonLogic();
    }

    public function evaluate(FlagDocument $document, string $flagKey, FlagType $type, mixed $default, ?string $targetingKey = null, array $attributes = []): Resolution
    {
        try {
            return $this->resolve($document, $flagKey, $type, $default, $targetingKey, $attributes);
        } catch (Throwable $failure) {
            return Resolution::error($default, EvaluationError::General, "Evaluating flag [{$flagKey}] failed: {$failure->getMessage()}");
        }
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function resolve(FlagDocument $document, string $flagKey, FlagType $type, mixed $default, ?string $targetingKey, array $attributes): Resolution
    {
        $flag = $document->flag($flagKey);
        if ($flag === null) {
            return Resolution::error($default, EvaluationError::FlagNotFound, "Flag [{$flagKey}] is not defined.");
        }

        $metadata = array_replace(self::scalars($document->metadata), $flag->metadata());

        if ($flag->isDisabled()) {
            return self::typed(new Resolution($default, null, EvaluationReason::Disabled, metadata: $metadata), $type, $default);
        }

        $targeting = $flag->targeting();
        if ($targeting !== null) {
            if (! RefResolver::withinLimits($targeting, $document->evaluators)) {
                return Resolution::error($default, EvaluationError::ParseError, sprintf(
                    'The targeting of flag [%s] expands past %d JSON values or %d levels.',
                    $flagKey,
                    RefResolver::MAX_VALUES,
                    RefResolver::MAX_DEPTH,
                ));
            }
            $targeting = RefResolver::expand($targeting, $document->evaluators);
        }

        if (! self::pythonTruthy($targeting)) {
            return self::typed(self::fallThrough($flag, $default, $metadata, EvaluationReason::Static), $type, $default);
        }

        if (! Json::isObject($targeting)) {
            return Resolution::error($default, EvaluationError::ParseError, "The targeting of flag [{$flagKey}] is not an object.");
        }

        try {
            $result = $this->logic->apply($targeting, $this->data($flagKey, $targetingKey, $attributes));
        } catch (UnknownOperator $unknown) {
            return Resolution::error($default, EvaluationError::ParseError, "Invalid targeting for flag [{$flagKey}]: {$unknown->getMessage()}");
        } catch (JsonLogicError $failure) {
            return Resolution::error($default, EvaluationError::General, "Targeting for flag [{$flagKey}] failed: {$failure->getMessage()}");
        }

        if ($result === null) {
            return self::typed(self::fallThrough($flag, $default, $metadata, EvaluationReason::Default), $type, $default);
        }

        $variant = match (true) {
            is_bool($result) => $result ? 'true' : 'false',
            default => PythonText::str($result),
        };
        if ($variant === null || ! $flag->hasVariant($variant) || $variant === '' || $flag->variantValue($variant) === null) {
            return Resolution::error($default, EvaluationError::General, sprintf(
                'Targeting for flag [%s] resolved [%s], which is not one of its variants.',
                $flagKey,
                $variant ?? get_debug_type($result),
            ));
        }

        return self::typed(new Resolution($flag->variantValue($variant), $variant, EvaluationReason::TargetingMatch, metadata: $metadata), $type, $default);
    }

    /**
     * The defaultVariant's value with $reason, or the caller's default with DEFAULT when the flag names none.
     *
     * @param  array<array-key, bool|int|float|string>  $metadata
     */
    private static function fallThrough(FlagDefinition $flag, mixed $default, array $metadata, EvaluationReason $reason): Resolution
    {
        $variant = $flag->defaultVariant() ?? (($flag->raw['defaultVariant'] ?? null) === '' && $flag->hasVariant('') ? '' : null);
        if ($variant === null) {
            return new Resolution($default, null, EvaluationReason::Default, metadata: $metadata);
        }

        if (! $flag->hasVariant($variant)) {
            return Resolution::error($default, EvaluationError::General, "Flag [{$flag->key}] names default variant [{$variant}], which is not one of its variants.");
        }

        return new Resolution($flag->variantValue($variant), $variant, $reason, metadata: $metadata);
    }

    /**
     * flagd's type check, then a float request's number as a float. An error passes through unchanged.
     */
    private static function typed(Resolution $resolution, FlagType $type, mixed $default): Resolution
    {
        if ($resolution->error !== null) {
            return $resolution;
        }

        $checked = $resolution->reason !== EvaluationReason::Disabled
            && ($resolution->reason !== EvaluationReason::Default || $resolution->variant !== null);
        $value = $resolution->value;
        if ($checked && ! $type->accepts($value) && ! ($type === FlagType::Float && is_bool($value))) {
            return Resolution::error($default, EvaluationError::TypeMismatch, sprintf('Flag resolved a %s value; a %s was requested.', get_debug_type($value), $type->value));
        }

        return $type === FlagType::Float && (is_int($value) || is_bool($value)) ? $resolution->withValue((float) $value) : $resolution;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, bool|int|float|string>
     */
    private static function scalars(array $metadata): array
    {
        return array_filter($metadata, static fn (mixed $value): bool => is_bool($value) || is_int($value) || is_float($value) || is_string($value));
    }

    /** Python's bool() of a JSON value: null, false, 0, 0.0, "", [] and {} are false. */
    private static function pythonTruthy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false, $value === 0, $value === '', $value === [] => false,
            is_float($value) => $value !== 0.0,
            $value instanceof stdClass => get_object_vars($value) !== [],
            default => true,
        };
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<array-key, mixed>
     */
    private function data(string $flagKey, ?string $targetingKey, array $attributes): array
    {
        $read = [];
        $copies = [];
        $data = self::containsDateTime($attributes, $read, []) ? self::arrayWithEpochMillis($attributes, $copies, []) : $attributes;
        $data['$flagd'] = ['flagKey' => $flagKey, 'timestamp' => $this->clock !== null ? ($this->clock)() : time()];
        $data['targetingKey'] = $targetingKey;

        return $data;
    }

    /**
     * Whether a DateTimeInterface sits anywhere in $value. Each object is read once (one may contain itself, or
     * be shared), and a PHP reference already open on this path (an array that contains itself) is not followed
     * again; arrays are values, read wherever they appear.
     *
     * @param  array<int, true>  $read  the ids of the objects read so far
     * @param  array<string, true>  $open  the ids of the PHP references open on this path
     */
    private static function containsDateTime(mixed $value, array &$read, array $open): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        if ($value instanceof stdClass) {
            if (isset($read[spl_object_id($value)])) {
                return false;
            }
            $read[spl_object_id($value)] = true;
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            $reference = ReflectionReference::fromArrayElement($value, $key)?->getId();
            if ($reference !== null && isset($open[$reference])) {
                continue;
            }
            if (self::containsDateTime($item, $read, $reference === null ? $open : [...$open, $reference => true])) {
                return true;
            }
        }

        return false;
    }

    /**
     * $value with every DateTimeInterface replaced by its epoch milliseconds, the caller's own values untouched:
     * arrays are rebuilt and every stdClass is copied — one copy per object, used wherever the object appears, so
     * an object that contains itself still does. A PHP reference already open on this path is kept as it is.
     *
     * @param  array<int, stdClass>  $copies  the copy of each object, by object id
     * @param  array<string, true>  $open  the ids of the PHP references open on this path
     */
    private static function withEpochMillis(mixed $value, array &$copies, array $open): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return self::epochMillis($value);
        }

        if ($value instanceof stdClass) {
            $id = spl_object_id($value);
            if (isset($copies[$id])) {
                return $copies[$id];
            }
            $copy = $copies[$id] = new stdClass;
            foreach (get_object_vars($value) as $member => $item) {
                $copy->{$member} = self::withEpochMillis($item, $copies, $open);
            }

            return $copy;
        }

        return is_array($value) ? self::arrayWithEpochMillis($value, $copies, $open) : $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<int, stdClass>  $copies
     * @param  array<string, true>  $open
     * @return array<array-key, mixed>
     */
    private static function arrayWithEpochMillis(array $value, array &$copies, array $open): array
    {
        $converted = [];
        foreach ($value as $key => $item) {
            $reference = ReflectionReference::fromArrayElement($value, $key)?->getId();
            $converted[$key] = $reference !== null && isset($open[$reference])
                ? $item
                : self::withEpochMillis($item, $copies, $reference === null ? $open : [...$open, $reference => true]);
        }

        return $converted;
    }

    /**
     * The whole microseconds since the epoch over 1000, correctly rounded: the exact decimal text, read once
     * (PHP's text-to-float conversion rounds correctly; dividing an integer past 2^53 would round twice).
     */
    private static function epochMillis(DateTimeInterface $value): float
    {
        $seconds = (int) $value->format('U');
        $micros = (int) $value->format('u');

        if ($seconds >= 0) {
            $sign = '';
            $digits = $seconds.str_pad((string) $micros, 6, '0', STR_PAD_LEFT);
        } elseif ($micros === 0) {
            $sign = '-';
            $digits = substr((string) $seconds, 1).'000000';
        } else {
            // seconds * 1e6 + micros is negative: its magnitude is (|seconds| - 1) * 1e6 + (1e6 - micros).
            $sign = '-';
            $digits = (-($seconds + 1)).str_pad((string) (1_000_000 - $micros), 6, '0', STR_PAD_LEFT);
        }

        return (float) ($sign.substr($digits, 0, -3).'.'.substr($digits, -3));
    }
}
