<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Closure;
use DateTimeInterface;
use Firefly\FeatureFlags\Definition\FlagDefinition;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use LogicException;
use stdClass;
use Throwable;
use WeakMap;

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
 * Types are flagd's except that a boolean is never a number (CONTRACT.md; Python's bool is an int, so flagd-core
 * answers float(True) for a float request): a float request accepts an integer variant and returns it as a float,
 * and where no type is checked (DISABLED, DEFAULT without a variant) the caller's default is returned as
 * float(default), as flagd-core and PyFly do; an object request accepts an object or a list. An object or list
 * value is a copy, so changing it changes neither the document nor a later answer. A targeting result names its
 * variant the way the reference turns it into a key: a boolean is "true"
 * or "false", text is itself, anything else is Python's str() of it (PythonText), so a `fractional` bucket written
 * as 1 selects the variant "1" and one written as 2.0 the variant "2.0".
 *
 * Two departures from the reference, both contract rulings: a boolean is never a number (above), and `$ref`s
 * resolve structurally and transitively within RefResolver's limits (flagd substitutes them textually); each
 * flag's expansion is worked out once per document and kept while the document lives (a WeakMap keyed by the
 * document object, which is shallowly immutable). A defaultVariant "" is no default variant, as in flagd, even when a
 * variant is named "" (R-default-empty).
 *
 * The JSON Logic data is the context attributes, then `$flagd` {flagKey, timestamp (Unix seconds)} and
 * `targetingKey`, which replace any attribute of those names. A DateTimeInterface in the attributes is evaluated
 * as its Unix epoch time in milliseconds (CONTRACT.md "Evaluation context"), found by a walk bounded as PyFly's is
 * (R-L-T5-bounds): containers more than RefResolver::MAX_DEPTH levels deep (the attributes are level 1) are not
 * entered, and after RefResolver::MAX_VALUES values the rest is left as it is, so neither deep nesting, an object
 * that contains itself, nor arrays shared over and over can exhaust time or memory; a date-time out there stays
 * one (JSON Logic reads it as an object). The caller's attributes are never modified: a container holding a
 * converted date-time is copied, one that holds none is passed as it is. Successful results carry the document's
 * scalar metadata with the flag's merged over it; errors carry none. evaluate() never throws: every failure is a
 * Resolution.
 */
final class DefaultFlagdEvaluator implements FlagdEvaluator
{
    private readonly JsonLogic $logic;

    /**
     * Per document, per flag key: [the flag's targeting with its references expanded], or null when it expands
     * past RefResolver's limits.
     *
     * @var WeakMap<FlagDocument, array<array-key, array{0: mixed}|null>>
     */
    private readonly WeakMap $expansions;

    /**
     * @param  (Closure(): int)|null  $clock  Unix seconds for `$flagd.timestamp`
     */
    public function __construct(private readonly ?Closure $clock = null)
    {
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('Feature flag bucketing needs 64-bit integers: (hash * totalWeight) >> 32 overflows a 32-bit PHP build.');
        }

        $this->logic = FlagdOperators::jsonLogic();
        $this->expansions = new WeakMap;
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

        $expansion = $this->expansion($document, $flagKey, $flag);
        if ($expansion === null) {
            return Resolution::error($default, EvaluationError::ParseError, sprintf(
                'The targeting of flag [%s] expands past %d JSON values or %d levels.',
                $flagKey,
                RefResolver::MAX_VALUES,
                RefResolver::MAX_DEPTH,
            ));
        }
        $targeting = $expansion[0];

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

        return self::typed(new Resolution(self::copied($flag->variantValue($variant)), $variant, EvaluationReason::TargetingMatch, metadata: $metadata), $type, $default);
    }

    /**
     * [the flag's targeting with its references expanded] ([null] for no targeting), or null when it expands past
     * RefResolver's limits: worked out on the first evaluation of the flag in this document and kept with the
     * document.
     *
     * @return array{0: mixed}|null
     */
    private function expansion(FlagDocument $document, string $flagKey, FlagDefinition $flag): ?array
    {
        $targeting = $flag->targeting();
        if ($targeting === null) {
            return [null];
        }

        $expansions = $this->expansions[$document] ?? [];
        if (! array_key_exists($flagKey, $expansions)) {
            $expansions[$flagKey] = RefResolver::withinLimits($targeting, $document->evaluators)
                ? [RefResolver::expand($targeting, $document->evaluators)]
                : null;
            $this->expansions[$document] = $expansions;
        }

        return $expansions[$flagKey];
    }

    /**
     * $value with every array and stdClass inside it rebuilt, in the same faithful form (`{}` stays a stdClass):
     * the document's own objects never leave the evaluator.
     */
    private static function copied(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass;
            foreach (get_object_vars($value) as $member => $item) {
                $copy->{$member} = self::copied($item);
            }

            return $copy;
        }

        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = self::copied($item);
            }

            return $copy;
        }

        return $value;
    }

    /**
     * The defaultVariant's value with $reason, or the caller's default with DEFAULT when the flag names none.
     *
     * @param  array<array-key, bool|int|float|string>  $metadata
     */
    private static function fallThrough(FlagDefinition $flag, mixed $default, array $metadata, EvaluationReason $reason): Resolution
    {
        $variant = $flag->defaultVariant();
        if ($variant === null) {
            return new Resolution($default, null, EvaluationReason::Default, metadata: $metadata);
        }

        if (! $flag->hasVariant($variant)) {
            return Resolution::error($default, EvaluationError::General, "Flag [{$flag->key}] names default variant [{$variant}], which is not one of its variants.");
        }

        return new Resolution(self::copied($flag->variantValue($variant)), $variant, $reason, metadata: $metadata);
    }

    /**
     * The type check (FlagType::accepts(): a boolean is never a number), then a float request's number as a float.
     * DISABLED and a DEFAULT without a variant carry the caller's default unchecked, as flagd does, and a float
     * request gets it as float(default) there — an integer or a boolean, as Python's float() reads them. An error
     * passes through unchanged.
     */
    private static function typed(Resolution $resolution, FlagType $type, mixed $default): Resolution
    {
        if ($resolution->error !== null) {
            return $resolution;
        }

        $checked = $resolution->reason !== EvaluationReason::Disabled
            && ($resolution->reason !== EvaluationReason::Default || $resolution->variant !== null);
        $value = $resolution->value;
        if ($checked && ! $type->accepts($value)) {
            return Resolution::error($default, EvaluationError::TypeMismatch, sprintf('Flag resolved a %s value; a %s was requested.', get_debug_type($value), $type->value));
        }

        $numeric = is_int($value) || (! $checked && is_bool($value));

        return $type === FlagType::Float && $numeric ? $resolution->withValue((float) $value) : $resolution;
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
        $budget = RefResolver::MAX_VALUES;
        $changed = false;
        $data = self::withEpochMillis($attributes, 1, $budget, $changed);
        $data = is_array($data) ? $data : $attributes;
        $data['$flagd'] = ['flagKey' => $flagKey, 'timestamp' => $this->clock !== null ? ($this->clock)() : time()];
        $data['targetingKey'] = $targetingKey;

        return $data;
    }

    /**
     * PyFly's _epoch_millis_context walk: every value read spends one unit of the budget (none left: the value
     * stays as it is); a date-time becomes its epoch milliseconds; a container past the depth limit is not
     * entered; a container is rebuilt only when something inside it changed ($changed), else handed back as is.
     *
     * @param  int  $level  the nesting level of $node (the attributes are level 1)
     */
    private static function withEpochMillis(mixed $node, int $level, int &$budget, bool &$changed): mixed
    {
        if (--$budget < 0) {
            return $node;
        }
        if ($node instanceof DateTimeInterface) {
            $changed = true;

            return self::epochMillis($node);
        }
        if ($level > RefResolver::MAX_DEPTH || (! is_array($node) && ! $node instanceof stdClass)) {
            return $node;
        }

        $inside = false;
        $members = [];
        foreach (is_array($node) ? $node : get_object_vars($node) as $key => $item) {
            $members[$key] = self::withEpochMillis($item, $level + 1, $budget, $inside);
        }
        if (! $inside) {
            return $node;
        }

        $changed = true;
        if (is_array($node)) {
            return $members;
        }
        $copy = new stdClass;
        foreach ($members as $member => $item) {
            $copy->{$member} = $item;
        }

        return $copy;
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
