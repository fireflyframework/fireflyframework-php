<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Registry;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The registry's bookkeeping in the application cache, which is what lets share-nothing PHP-FPM workers share
 * one refresh schedule and one change detector. Every call tolerates a failing store: reads answer "nothing
 * known", writes are dropped, `first()` lets every caller through and `lock()` grants a no-op lock — so a cache
 * outage costs extra source loads in each process, never the flags themselves.
 *
 * degraded() tells whether the LAST call failed: it clears as soon as the store answers again, so a long-lived
 * process resumes sharing (and announcing changes) after an outage. The outage is logged once per process.
 *
 * Every application needs a cache prefix of its own (Laravel's `cache.prefix`): two applications sharing one store
 * and one prefix would share their flag bookkeeping as well.
 */
final class CacheBook
{
    public const string PREFIX = 'firefly:feature-flags:';

    private bool $degraded = false;

    private bool $warned = false;

    public function __construct(
        private readonly Repository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function get(string $key): mixed
    {
        try {
            $value = $this->cache->get(self::PREFIX.$key);
            $this->degraded = false;

            return $value;
        } catch (Throwable $failure) {
            $this->degrade($failure);

            return null;
        }
    }

    public function put(string $key, mixed $value): void
    {
        try {
            $this->cache->forever(self::PREFIX.$key, $value);
            $this->degraded = false;
        } catch (Throwable $failure) {
            $this->degrade($failure);
        }
    }

    /** True for exactly one caller per key and window (Cache::add); every caller while the store is down. */
    public function first(string $key, int $seconds): bool
    {
        try {
            $first = $this->cache->add(self::PREFIX.$key, true, max(1, $seconds));
            $this->degraded = false;

            return $first;
        } catch (Throwable $failure) {
            $this->degrade($failure);

            return true;
        }
    }

    /**
     * A non-blocking lock: the release closure, or null when another process holds it. A store without atomic
     * locks (or a store that is down) grants a no-op lock rather than refusing every refresh.
     *
     * @return (Closure(): void)|null
     */
    public function lock(string $key, int $seconds): ?Closure
    {
        try {
            $store = $this->cache->getStore();
            if (! $store instanceof LockProvider) {
                $this->degraded = false;

                return static function (): void {};
            }

            $lock = $store->lock(self::PREFIX.$key, max(1, $seconds));
            $acquired = $lock->get() === true;
            $this->degraded = false;

            return $acquired ? static function () use ($lock): void {
                $lock->release();
            } : null;
        } catch (Throwable $failure) {
            $this->degrade($failure);

            return static function (): void {};
        }
    }

    public function degraded(): bool
    {
        return $this->degraded;
    }

    private function degrade(Throwable $failure): void
    {
        $this->degraded = true;
        if ($this->warned) {
            return;
        }

        $this->warned = true;
        $this->logger->warning('Feature flags cannot reach the cache store ({error}); each process loads its flag sources itself until the store is back.', ['error' => $failure->getMessage()]);
    }
}
