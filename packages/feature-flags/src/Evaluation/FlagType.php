<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use stdClass;

/** The five OpenFeature value types, with flagd's type check (a float resolution accepts an integer variant). */
enum FlagType: string
{
    case Boolean = 'boolean';
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Object = 'object';

    public static function ofDefault(mixed $default): self
    {
        return match (true) {
            is_bool($default) => self::Boolean,
            is_int($default) => self::Integer,
            is_float($default) => self::Float,
            is_string($default) => self::String,
            default => self::Object,
        };
    }

    /** Float accepts int and float; Object accepts arrays and stdClass. */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::Boolean => is_bool($value),
            self::String => is_string($value),
            self::Integer => is_int($value),
            self::Float => is_int($value) || is_float($value),
            self::Object => is_array($value) || $value instanceof stdClass,
        };
    }

    /**
     * @return bool|string|int|float|array<never, never>
     */
    public function zero(): bool|string|int|float|array
    {
        return match ($this) {
            self::Boolean => false,
            self::String => '',
            self::Integer => 0,
            self::Float => 0.0,
            self::Object => [],
        };
    }
}
