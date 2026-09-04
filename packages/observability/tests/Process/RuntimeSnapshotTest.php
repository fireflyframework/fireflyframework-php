<?php

declare(strict_types=1);

use Firefly\Observability\Process\RuntimeSnapshot;

/**
 * The defect this pins: `(int) '512M'` is 512, because PHP's cast stops at the first non-digit. A dashboard fed
 * that number would show a half-kilobyte memory limit next to a two-megabyte usage figure — a reading that
 * looks like an emergency and is wrong by a factor of a million.
 */
it('parses PHP shorthand memory limits instead of truncating them at the first non-digit', function () {
    expect(RuntimeSnapshot::parseMemoryLimit('512M'))->toBe(536_870_912)
        ->and(RuntimeSnapshot::parseMemoryLimit('128m'))->toBe(134_217_728)
        ->and(RuntimeSnapshot::parseMemoryLimit('1G'))->toBe(1_073_741_824)
        ->and(RuntimeSnapshot::parseMemoryLimit('64K'))->toBe(65_536)
        ->and(RuntimeSnapshot::parseMemoryLimit('134217728'))->toBe(134_217_728);
});

it('reports -1 for an unlimited or unparseable limit rather than inventing a number', function () {
    expect(RuntimeSnapshot::parseMemoryLimit('-1'))->toBe(-1)
        ->and(RuntimeSnapshot::parseMemoryLimit(''))->toBe(-1)
        ->and(RuntimeSnapshot::parseMemoryLimit('lots'))->toBe(-1)
        ->and(RuntimeSnapshot::parseMemoryLimit('512MB'))->toBe(-1);
});

it('reads live memory numbers off the current process', function () {
    $memory = (new RuntimeSnapshot)->memory();

    expect(array_keys($memory))->toBe(['usedBytes', 'peakBytes', 'limitBytes', 'limit'])
        ->and($memory['usedBytes'])->toBeGreaterThan(0)
        // real_usage=true on both calls, so peak is measured on the same basis as current and can never be the
        // smaller of the two.
        ->and($memory['peakBytes'])->toBeGreaterThanOrEqual($memory['usedBytes'])
        ->and($memory['limit'])->toBe(ini_get('memory_limit'))
        ->and($memory['limitBytes'])->toBe(RuntimeSnapshot::parseMemoryLimit((string) ini_get('memory_limit')));
});

/**
 * opcache is usually absent from a CLI test run, so this asserts the CONTRACT both ways: null when there is
 * nothing to report (extension unloaded, opcache disabled, or `opcache.restrict_api` forbidding the call —
 * which emits an E_WARNING that Laravel's handler turns into an ErrorException, hence the guard inside), and
 * the exact aggregate-counter shape when there is.
 */
it('reports either null or the exact opcache counter shape, never a half-populated one', function () {
    $opcache = (new RuntimeSnapshot)->opcache();

    if ($opcache === null) {
        expect($opcache)->toBeNull();

        return;
    }

    expect(array_keys($opcache))->toBe([
        'enabled', 'hits', 'misses', 'hitRate', 'usedMemoryBytes', 'freeMemoryBytes', 'wastedMemoryBytes', 'cachedScripts',
    ])
        ->and($opcache['enabled'])->toBeBool()
        ->and($opcache['hitRate'])->toBeFloat()
        // The absolute paths of every compiled script are NOT read (opcache_get_status(false)): they hand a
        // reader the deployment layout, the vendor tree and often the OS username.
        ->and($opcache)->not->toHaveKey('scripts');
});
