<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Tests\Support\FixedClock;
use Firefly\FeatureFlags\Tests\Support\FlakyCache;
use Firefly\FeatureFlags\Tests\Support\HookedLockStore;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\StubFlagSource;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\SessionStore;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as Session;

it('keeps its entries under one prefix, lets one caller through per window, and locks without blocking', function (): void {
    $store = new ArrayStore;
    $cache = new Repository($store);
    $book = new CacheBook($cache, new RecordingLogger);

    $book->put('source:file', ['name' => 'file']);
    $release = $book->lock('lock:file', 5);

    expect($book->get('source:file'))->toBe(['name' => 'file'])
        ->and($cache->get('firefly:feature-flags:source:file'))->toBe(['name' => 'file'])
        ->and($book->first('expired:old', 60))->toBeTrue()
        ->and($book->first('expired:old', 60))->toBeFalse()
        ->and($release)->not->toBeNull()
        ->and($book->lock('lock:file', 5))->toBeNull()
        ->and($book->degraded())->toBeFalse();

    if ($release !== null) {
        $release();
    }

    expect($book->lock('lock:file', 5))->not->toBeNull();
});

it('grants a no-op lock on a store without atomic locks', function (): void {
    $book = new CacheBook(new Repository(new SessionStore(new Session('flags', new ArraySessionHandler(10)))), new RecordingLogger);

    expect($book->lock('lock:http', 5))->not->toBeNull()
        ->and($book->lock('lock:http', 5))->not->toBeNull();
});

it('tolerates a failing store: nothing known, writes dropped, every caller first, a no-op lock', function (): void {
    $cache = new FlakyCache;
    $cache->down = true;
    $logger = new RecordingLogger;
    $book = new CacheBook($cache, $logger);

    $book->put('source:store', ['name' => 'store']);

    expect($book->get('source:store'))->toBeNull()
        ->and($book->first('changed:1', 10))->toBeTrue()
        ->and($book->first('changed:1', 10))->toBeTrue()
        ->and($book->lock('lock:store', 5))->not->toBeNull()
        ->and($book->degraded())->toBeTrue()
        ->and($logger->count('warning', 'cannot reach the cache store (redis is gone)'))->toBe(1);
});

it('is degraded only until the store answers again, and warns once per outage', function (Closure $call): void {
    $cache = new FlakyCache;
    $logger = new RecordingLogger;
    $book = new CacheBook($cache, $logger);

    $cache->down = true;
    $call($book);
    $whileDown = $book->degraded();
    $cache->down = false;
    $call($book);
    $afterRecovery = $book->degraded();
    $cache->down = true;
    $call($book);

    expect($whileDown)->toBeTrue()
        ->and($afterRecovery)->toBeFalse()
        ->and($book->degraded())->toBeTrue()
        ->and($logger->count('warning', 'cannot reach the cache store'))->toBe(2);
})->with([
    'get' => [static fn (CacheBook $book): mixed => $book->get('composed')],
    'put' => [static function (CacheBook $book): void {
        $book->put('composed', ['generation' => 1]);
    }],
    'first' => [static fn (CacheBook $book): bool => $book->first('changed:1', 10)],
    'lock' => [static function (CacheBook $book): void {
        $release = $book->lock('lock:store', 5);
        if ($release !== null) {
            $release();
        }
    }],
]);

it('tolerates a store that fails while releasing a lock: the refresh goes on and the flags evaluate', function (): void {
    $failingRelease = static function (string $lock): void {
        throw new RuntimeException('redis is gone');
    };
    $store = new HookedLockStore;
    $store->onRelease = $failingRelease;
    $logger = new RecordingLogger;
    $book = new CacheBook(new Repository($store), $logger);
    $release = $book->lock('lock:http', 5);
    if ($release !== null) {
        $release();
    }
    $degraded = $book->degraded();

    $bootStore = new HookedLockStore;
    $bootStore->onRelease = $failingRelease;
    $bootLogger = new RecordingLogger;
    $registry = new FlagRegistry([new StubFlagSource('http', 300, 30.0, ['h' => true])], new CacheBook(new Repository($bootStore), $bootLogger), new RecordingApplicationEventPublisher, $bootLogger, (new FixedClock)(...));
    $registry->start();

    expect($release)->not->toBeNull()
        ->and($degraded)->toBeTrue()
        ->and($logger->count('warning', 'cannot reach the cache store (redis is gone)'))->toBe(1)
        ->and($registry->document()->flag('h'))->not->toBeNull()
        ->and($bootLogger->count('warning', 'cannot reach the cache store (redis is gone)'))->toBe(1);
});
