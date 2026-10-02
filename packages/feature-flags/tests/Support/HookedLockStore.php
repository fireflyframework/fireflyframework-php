<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Closure;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;

/**
 * An array store whose locks let a test interleave workers: $beforeLock runs once, when the next lock is asked for
 * and before it is acquired (another worker gets there first); $onRelease runs at every release, before the lock is
 * let go, and may throw (the store dropping mid-refresh).
 */
final class HookedLockStore extends ArrayStore
{
    /** @var (Closure(string): void)|null */
    public ?Closure $beforeLock = null;

    /** @var (Closure(string): void)|null */
    public ?Closure $onRelease = null;

    /**
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $hook = $this->beforeLock;
        $this->beforeLock = null;
        if ($hook !== null) {
            $hook($name);
        }

        return new class($this, $name, $seconds, $owner, $this->onRelease) extends ArrayLock
        {
            /** @param  (Closure(string): void)|null  $onRelease */
            public function __construct(ArrayStore $store, string $name, int $seconds, ?string $owner, private readonly ?Closure $onRelease)
            {
                parent::__construct($store, $name, $seconds, $owner);
            }

            public function release(): bool
            {
                if ($this->onRelease !== null) {
                    ($this->onRelease)($this->name);
                }

                return parent::release();
            }
        };
    }
}
