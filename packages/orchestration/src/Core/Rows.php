<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/**
 * Typed reads over the pure-array rows this package moves between processes: a compiled manifest row, a
 * persisted execution payload, a dead-letter record. Each arrives as `mixed` (from `require`, `json_decode` or
 * a cache store), and every reader would otherwise repeat the same is_string/is_int narrowing. A malformed
 * value falls back to the default rather than throwing, because the writer of every such row is this package.
 *
 * @internal
 */
final class Rows
{
    /** @param array<array-key, mixed> $row */
    public static function string(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? null;

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => $default,
        };
    }

    /** @param array<array-key, mixed> $row */
    public static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $row */
    public static function int(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? null;

        return match (true) {
            is_int($value) => $value,
            is_numeric($value) => (int) $value,
            default => $default,
        };
    }

    /** @param array<array-key, mixed> $row */
    public static function float(array $row, string $key, float $default = 0.0): float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param array<array-key, mixed> $row */
    public static function bool(array $row, string $key, bool $default = false): bool
    {
        $value = $row[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<mixed>
     */
    public static function list(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<string>
     */
    public static function strings(array $row, string $key): array
    {
        $strings = [];
        foreach (self::list($row, $key) as $value) {
            if (is_string($value) || is_int($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    public static function map(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $k => $v) {
            $map[(string) $k] = $v;
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function nullableMap(array $row, string $key): ?array
    {
        return is_array($row[$key] ?? null) ? self::map($row, $key) : null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, string>
     */
    public static function stringMap(array $row, string $key): array
    {
        $map = [];
        foreach (self::map($row, $key) as $k => $v) {
            if (is_bool($v)) {
                $map[$k] = $v ? 'true' : 'false';
            } elseif (is_string($v) || is_int($v) || is_float($v)) {
                $map[$k] = (string) $v;
            }
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, int>
     */
    public static function intMap(array $row, string $key): array
    {
        $map = [];
        foreach (self::map($row, $key) as $k => $v) {
            if (is_numeric($v)) {
                $map[$k] = (int) $v;
            }
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<array<string, mixed>>
     */
    public static function rows(array $row, string $key): array
    {
        $rows = [];
        foreach (self::list($row, $key) as $value) {
            if (is_array($value)) {
                $rows[] = self::map(['row' => $value], 'row');
            }
        }

        return $rows;
    }
}
