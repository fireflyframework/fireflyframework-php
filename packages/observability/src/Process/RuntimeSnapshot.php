<?php

declare(strict_types=1);

namespace Firefly\Observability\Process;

use Throwable;

/**
 * Reads the live runtime numbers a dashboard can plot — memory and opcache — off the current PHP process.
 *
 * Split out of ProcessEndpoint rather than inlined there so the two genuinely tricky parts (parsing
 * `memory_limit`, and getting an opcache status without the call itself becoming the outage) are unit-testable
 * without booting an actuator. Everything here is a pull: nothing is cached, because the whole point of the
 * numbers is that they are current at the moment of the request.
 *
 * DELIBERATELY NON-SENSITIVE. `opcache_get_status(false)` is called with `false` on purpose: the default `true`
 * returns a `scripts` array listing the ABSOLUTE PATH of every compiled file, which hands a reader the
 * application's deployment layout, its vendor tree, and often the operating-system username in the path prefix.
 * None of that belongs behind an endpoint someone may expose. Only the aggregate counters are read.
 */
final class RuntimeSnapshot
{
    /**
     * `limitBytes` is -1 for an unlimited process, matching the `memory_limit = -1` ini spelling, and `limit` is
     * the raw ini string so a dashboard can display "512M" rather than re-deriving it from the byte count.
     *
     * @return array{usedBytes: int, peakBytes: int, limitBytes: int, limit: string}
     */
    public function memory(): array
    {
        // No false-guard on ini_get(): memory_limit is a core PHP_INI_ALL directive that always exists, so the
        // documented "false when the directive is unknown" branch is unreachable — PHPStan knows this from its
        // ini_get extension and rejects the guard as dead code, which is the correct call.
        $raw = ini_get('memory_limit');

        return [
            // real_usage=true (the argument to both calls) reports memory actually requested from the OS rather
            // than the smaller number PHP's allocator has handed out internally. It is the figure that has to be
            // compared against memory_limit, because memory_limit is enforced against it.
            'usedBytes' => memory_get_usage(true),
            'peakBytes' => memory_get_peak_usage(true),
            'limitBytes' => self::parseMemoryLimit($raw),
            'limit' => $raw,
        ];
    }

    /**
     * Bytes for a PHP shorthand ini value ("512M", "1G", "134217728"), or -1 for unlimited/unparseable.
     *
     * Not `(int) $limit`: PHP's cast stops at the first non-digit, so "512M" would become 512 and a dashboard
     * would report a half-kilobyte memory limit next to a two-megabyte usage figure — a number that looks like
     * an emergency and is off by a factor of a million.
     */
    public static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);

        if (preg_match('/^(-?\d+)\s*([KMG])?$/i', $limit, $matches) !== 1) {
            return -1;
        }

        $value = (int) $matches[1];
        if ($value < 0) {
            return -1;
        }

        return match (strtoupper($matches[2] ?? '')) {
            'K' => $value * 1024,
            'M' => $value * 1024 * 1024,
            'G' => $value * 1024 * 1024 * 1024,
            default => $value,
        };
    }

    /**
     * The opcache aggregate counters, or null when there is nothing to report.
     *
     * Null covers three distinct situations that a caller cannot usefully tell apart anyway: the extension is
     * not loaded (CLI runs usually), it is loaded but disabled, or `opcache.restrict_api` forbids this script
     * from asking. The last one is why the call is wrapped: a blocked `opcache_get_status()` emits an E_WARNING,
     * and Laravel's error handler converts warnings into ErrorException — so the naive call would throw out of
     * an actuator endpoint and render a 500 for a machine that simply has the API locked down. A hardened
     * production box is exactly where an operator opens this endpoint.
     *
     * `hitRate` is opcache's own `opcache_hit_rate`, a PERCENTAGE (0-100), rounded to two decimals. A rate below
     * ~95% on a warm process usually means the cache is too small or is being thrashed by `revalidate_freq`.
     *
     * @return array{enabled: bool, hits: int, misses: int, hitRate: float, usedMemoryBytes: int, freeMemoryBytes: int, wastedMemoryBytes: int, cachedScripts: int}|null
     */
    public function opcache(): ?array
    {
        if (! function_exists('opcache_get_status')) {
            return null;
        }

        try {
            $status = opcache_get_status(false);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($status)) {
            return null;
        }

        $statistics = $status['opcache_statistics'] ?? null;
        $memory = $status['memory_usage'] ?? null;

        if (! is_array($statistics) || ! is_array($memory)) {
            return null;
        }

        return [
            'enabled' => ($status['opcache_enabled'] ?? false) === true,
            'hits' => $this->int($statistics['hits'] ?? null),
            'misses' => $this->int($statistics['misses'] ?? null),
            'hitRate' => round($this->float($statistics['opcache_hit_rate'] ?? null), 2),
            'usedMemoryBytes' => $this->int($memory['used_memory'] ?? null),
            'freeMemoryBytes' => $this->int($memory['free_memory'] ?? null),
            'wastedMemoryBytes' => $this->int($memory['wasted_memory'] ?? null),
            'cachedScripts' => $this->int($statistics['num_cached_scripts'] ?? null),
        ];
    }

    /**
     * opcache reports large counters as floats once they exceed PHP_INT_MAX on 32-bit builds, and older
     * extension versions have shipped strings for one or two of these. Coerce through is_numeric rather than
     * asserting a shape the extension does not actually guarantee across versions.
     */
    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
