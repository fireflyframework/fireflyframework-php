<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\ExecutionLockedException;
use Firefly\Orchestration\Core\ExecutionLock;
use Firefly\Scheduling\Lock\CacheLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('is re-entrant within a process and releases on the way out', function (): void {
    $cache = new Repository(new ArrayStore);
    $lock = new ExecutionLock(new CacheLock($cache), 30.0, 0.1);

    $result = $lock->synchronized('c-1', static fn (): string => $lock->synchronized('c-1', static fn (): string => 'nested'));

    expect($result)->toBe('nested')
        ->and((new CacheLock($cache))->tryAcquire(ExecutionLock::name('c-1'), 1.0))->toBeTrue();
});

it('refuses a second process while the first holds the execution', function (): void {
    $cache = new Repository(new ArrayStore);
    $first = new ExecutionLock(new CacheLock($cache), 30.0, 0.1);
    $second = new ExecutionLock(new CacheLock($cache), 30.0, 0.1);

    $first->synchronized('c-1', static function () use ($second): void {
        expect(fn () => $second->synchronized('c-1', static fn (): null => null))->toThrow(ExecutionLockedException::class, 'c-1');
    });

    expect($second->synchronized('c-1', static fn (): string => 'free again'))->toBe('free again');
});
