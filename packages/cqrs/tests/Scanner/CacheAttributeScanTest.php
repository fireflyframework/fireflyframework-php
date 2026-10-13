<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\CachedQueryPolicy;
use Firefly\Cqrs\Cache\InvalidationRule;
use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerManifestCompiler;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Firefly\Cqrs\Tests\CacheScannerFixtures\EntryPosted;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerBalance;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerTotals;

it('compiles #[CachedQuery] into per-query policies with the class as the default group', function () {
    $result = (new HandlerScanner)->scan(['Firefly\\Cqrs\\Tests\\CacheScannerFixtures\\' => dirname(__DIR__).'/CacheScannerFixtures']);

    expect($result['cachedQueries'])->toEqual([
        LedgerBalance::class => new CachedQueryPolicy(LedgerBalance::class, 60, LedgerBalance::class),
        LedgerTotals::class => new CachedQueryPolicy(LedgerTotals::class, null, 'ledger.totals'),
    ]);
});

it('compiles #[InvalidatesQuery] into rules keyed by the event class and its event type', function () {
    $result = (new HandlerScanner)->scan(['Firefly\\Cqrs\\Tests\\CacheScannerFixtures\\' => dirname(__DIR__).'/CacheScannerFixtures']);

    expect($result['invalidations'])->toEqual([
        new InvalidationRule(EntryPosted::class, 'EntryPosted', LedgerBalance::class, null, ['account' => 'account']),
        new InvalidationRule(EntryPosted::class, 'ledger.entry-posted', LedgerTotals::class),
    ])
        ->and($result['invalidations'][1]->evictsGroup())->toBeTrue()
        ->and($result['invalidations'][0]->evictsGroup())->toBeFalse();
});

it('refuses a rule that names both a key template and a with map, and a TTL below one second', function () {
    $scan = static fn (): array => (new HandlerScanner)->scan(['Firefly\\Cqrs\\Tests\\CacheScannerBadFixtures\\' => dirname(__DIR__).'/CacheScannerBadFixtures']);

    expect($scan)->toThrow(CqrsConfigurationException::class);
});

// Isolates the TTL guard: the scanner names classes after the files it walks, so a directory holding only a
// ZeroTtlQuery.php placeholder reaches the real ZeroTtlQuery fixture (autoloaded from its own file) and nothing else —
// AmbiguousRuleEvent, which sorts first in CacheScannerBadFixtures, can no longer short the scan before the TTL check.
it('refuses a TTL below one second on its own', function () {
    $dir = sys_get_temp_dir().'/firefly-cqrs-zero-ttl-'.bin2hex(random_bytes(6));
    mkdir($dir);
    touch($dir.'/ZeroTtlQuery.php');

    try {
        expect(static fn (): array => (new HandlerScanner)->scan(['Firefly\\Cqrs\\Tests\\CacheScannerBadFixtures\\' => $dir]))
            ->toThrow(CqrsConfigurationException::class, 'declares ttl 0');
    } finally {
        @unlink($dir.'/ZeroTtlQuery.php');
        @rmdir($dir);
    }
});

// Each `with` guard is isolated the same way: a directory holding only the event's placeholder file scans that one
// event, and its query fixture autoloads from CacheScannerBadFixtures without being scanned itself.
dataset('with map misconfigurations', [
    'an unknown query property' => ['TypoWithEvent', 'maps [acount]'],
    'an unmapped query property' => ['PartialWithEvent', 'leaves [page] unmapped'],
    'a query with private state' => ['PrivateWithEvent', 'is not public'],
    'a Cacheable query' => ['CacheableWithEvent', 'use a key template instead'],
]);

it('refuses a with map that does not name exactly the query\'s public properties', function (string $event, string $message) {
    $dir = sys_get_temp_dir().'/firefly-cqrs-with-map-'.bin2hex(random_bytes(6));
    mkdir($dir);
    touch($dir.'/'.$event.'.php');

    try {
        expect(static fn (): array => (new HandlerScanner)->scan(['Firefly\\Cqrs\\Tests\\CacheScannerBadFixtures\\' => $dir]))
            ->toThrow(CqrsConfigurationException::class, $message);
    } finally {
        @unlink($dir.'/'.$event.'.php');
        @rmdir($dir);
    }
})->with('with map misconfigurations');

it('round-trips both sections through the compiled manifest file', function () {
    $result = (new HandlerScanner)->scan(['Firefly\\Cqrs\\Tests\\CacheScannerFixtures\\' => dirname(__DIR__).'/CacheScannerFixtures']);
    $path = sys_get_temp_dir().'/firefly-cqrs-cache-manifest-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new HandlerManifestCompiler)->write($result['handlers'], $result['destinations'], $path, $result['cachedQueries'], $result['invalidations']);
        $manifest = HandlerManifest::load($path);

        expect($manifest->cachedQueries())->toEqual($result['cachedQueries'])
            ->and($manifest->invalidations())->toEqual($result['invalidations'])
            ->and($manifest->cachePolicyFor(LedgerTotals::class)?->group)->toBe('ledger.totals')
            ->and($manifest->cachePolicyFor(EntryPosted::class))->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('still loads a handler manifest compiled before the cache sections existed', function () {
    $manifest = HandlerManifest::fromArray(['handlers' => [], 'destinations' => ['App\\E' => 'x']]);

    expect($manifest->cachedQueries())->toBe([])
        ->and($manifest->invalidations())->toBe([])
        ->and($manifest->destinations())->toBe(['App\\E' => 'x']);
});
