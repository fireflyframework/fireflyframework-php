<?php

declare(strict_types=1);

namespace Firefly\Admin;

/**
 * Presentation helpers for the values actuator endpoints return as raw numbers.
 *
 * The dashboard used to render `2097152` for a memory gauge and `0.0312` for a timer. Both are correct and
 * neither is readable: an operator scanning a page should see `2.0 MB` and `31.2 ms` without doing
 * arithmetic. Formatting lives here rather than in the endpoints because the JSON surface must keep
 * returning machine-readable numbers — Prometheus scrapes it.
 */
final class Format
{
    /** Metric names whose values are byte counts. Matched as a suffix, the convention Prometheus uses. */
    private const BYTE_SUFFIXES = ['_bytes', '_memory', '.bytes', '.memory'];

    /** Metric names whose values are durations in seconds. */
    private const SECOND_SUFFIXES = ['_seconds', '.seconds', '_duration', '.duration'];

    public static function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = abs($bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        $decimals = $unit === 0 ? 0 : ($value < 10 ? 1 : 0);

        return ($bytes < 0 ? '-' : '').number_format($value, $decimals).' '.$units[$unit];
    }

    /** A duration given in SECONDS, rendered at whatever scale keeps it readable. */
    public static function duration(float $seconds): string
    {
        return match (true) {
            $seconds < 0.001 => number_format($seconds * 1_000_000, 0).' µs',
            $seconds < 1 => number_format($seconds * 1000, $seconds < 0.1 ? 1 : 0).' ms',
            $seconds < 60 => number_format($seconds, 2).' s',
            $seconds < 3600 => floor($seconds / 60).'m '.number_format(fmod($seconds, 60), 0).'s',
            default => floor($seconds / 3600).'h '.floor(fmod($seconds, 3600) / 60).'m',
        };
    }

    public static function milliseconds(float $ms): string
    {
        return self::duration($ms / 1000);
    }

    /** A plain count, thousands-separated so six figures are scannable. */
    public static function count(float $value): string
    {
        return $value === floor($value) && abs($value) < 1e15
            ? number_format($value)
            : rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    /**
     * Formats a measurement by inferring its unit from the METER NAME, the way a Prometheus consumer does.
     * There is no unit metadata on the wire — `php_memory_peak_bytes` says what it is in its own name, which
     * is exactly the convention the exposition format relies on.
     */
    public static function measurement(string $meterName, float $value): string
    {
        $name = strtolower($meterName);

        foreach (self::BYTE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return self::bytes($value);
            }
        }

        foreach (self::SECOND_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return self::duration($value);
            }
        }

        return self::count($value);
    }

    /** "3 minutes ago" for a unix timestamp, or an ISO instant when it is older than a day. */
    public static function since(float $timestamp, float $now): string
    {
        $delta = max(0.0, $now - $timestamp);

        return match (true) {
            $delta < 2 => 'just now',
            $delta < 60 => (int) $delta.'s ago',
            $delta < 3600 => (int) ($delta / 60).'m ago',
            $delta < 86400 => (int) ($delta / 3600).'h ago',
            default => date('Y-m-d H:i', (int) $timestamp),
        };
    }

    /** The share one value takes of a maximum, clamped to 0..100 for a bar width. */
    public static function percent(float $value, float $max): float
    {
        if ($max <= 0) {
            return 0.0;
        }

        return max(0.0, min(100.0, $value / $max * 100));
    }

    /**
     * One health-indicator detail, rendered for a human.
     *
     * Indicator details are a free-form map, so there is no unit metadata to read — but the keys the shipped
     * indicators use are conventional, and a disk-space indicator reporting `total=994610155520` is a number
     * nobody can parse at a glance. Byte-ish keys are formatted as sizes; a filesystem path is elided from
     * the LEFT, because the tail of a path is the part that identifies it.
     */
    public static function detail(string $key, mixed $value): string
    {
        $name = strtolower($key);

        if (is_numeric($value) && in_array($name, ['total', 'free', 'used', 'peak', 'limit', 'threshold', 'available', 'size'], true)) {
            return self::bytes((float) $value);
        }

        if (is_string($value) && in_array($name, ['path', 'directory', 'dir', 'file'], true)) {
            return self::elide($value, 44);
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }

    /** Keeps the TAIL of an over-long string, which for a path or a class name is the identifying half. */
    public static function elide(string $value, int $max): string
    {
        return strlen($value) <= $max ? $value : '…'.substr($value, -($max - 1));
    }

    /** A short, readable class name with its namespace kept as a separate, dimmable prefix. */
    public static function shortClass(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    public static function namespaceOf(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? '' : substr($fqcn, 0, $position + 1);
    }
}
