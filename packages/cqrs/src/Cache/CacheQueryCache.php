<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The query cache on a Laravel cache store (firefly.cqrs.query.cache.provider=cache).
 *
 * GROUPS ARE GENERATIONS, NOT TAGS. Cache tags exist only on the redis, memcached and array stores; a query cache
 * has to work on the file and database stores too. So each group owns a random generation token, and every entry
 * of the group is stored under a key that includes it:
 *
 *     <prefix>g:<sha256(group)>                       => the group's current generation
 *     <prefix>q:<sha256(group ␟ generation ␟ key)>    => one cached result
 *
 * evictGroup() forgets the generation; the next lookup starts a fresh random one, and no entry written under an
 * older generation is reachable again — they expire on their own TTL (which is why the module guide tells you to
 * set one). That also settles the classic read/evict race in the group's favour: a handler that read the database
 * before a commit stores its result under the generation it looked up, which the commit's eviction has just made
 * unreachable. A single-key eviction (evictKey) does not have that property; it is the cheaper, narrower tool.
 *
 * A generation is created with add() and a fixed TTL: Laravel's Repository::add() is only atomic when it is given a
 * TTL, and two processes starting a generation at once must agree on one. Keys are hashed, so a group or key of any
 * length fits every store's key limit (memcached: 250 bytes; the database store: 255).
 *
 * A CACHE FAILURE NEVER FAILS A QUERY: every operation catches, logs a warning and answers a miss — the handler
 * runs, exactly as it would with no cache at all. null is never stored, because DefaultQueryBus reads null as a
 * miss.
 */
final class CacheQueryCache implements GroupedQueryCache
{
    public const string DEFAULT_PREFIX = 'firefly:cqrs:';

    /** Thirty days: long enough to outlive any entry TTL worth using; a generation that expires only costs misses. */
    public const int GENERATION_TTL = 2_592_000;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = self::DEFAULT_PREFIX,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function get(string $key): mixed
    {
        return $this->lookup('', $key);
    }

    public function put(string $key, mixed $value, ?int $ttl): void
    {
        $this->store('', $key, $value, $ttl);
    }

    public function evict(string $key): void
    {
        $this->evictKey('', $key);
    }

    public function lookup(string $group, string $key): mixed
    {
        try {
            $generation = $this->generation($group, true);

            return $generation === null ? null : $this->cache->get($this->entryKey($group, $generation, $key));
        } catch (Throwable $e) {
            $this->report('lookup', $group, $e);

            return null;
        }
    }

    public function store(string $group, string $key, mixed $value, ?int $ttl): void
    {
        if ($value === null) {
            return;
        }

        try {
            $generation = $this->generation($group, true);
            if ($generation === null) {
                return;
            }

            $entry = $this->entryKey($group, $generation, $key);
            if ($ttl === null) {
                $this->cache->forever($entry, $value);
            } else {
                $this->cache->put($entry, $value, max(1, $ttl));
            }
        } catch (Throwable $e) {
            $this->report('store', $group, $e);
        }
    }

    public function evictKey(string $group, string $key): void
    {
        try {
            $generation = $this->generation($group, false);
            if ($generation !== null) {
                $this->cache->forget($this->entryKey($group, $generation, $key));
            }
        } catch (Throwable $e) {
            $this->report('evictKey', $group, $e);
        }
    }

    public function evictGroup(string $group): void
    {
        try {
            $this->cache->forget($this->generationKey($group));
        } catch (Throwable $e) {
            $this->report('evictGroup', $group, $e);
        }
    }

    /** The group's generation; with $create, started now when it has none. null when none could be started. */
    private function generation(string $group, bool $create): ?string
    {
        $key = $this->generationKey($group);
        $current = $this->cache->get($key);
        if (is_string($current) && $current !== '') {
            return $current;
        }

        if (! $create) {
            return null;
        }

        $fresh = bin2hex(random_bytes(8));
        if ($this->cache->add($key, $fresh, self::GENERATION_TTL)) {
            return $fresh;
        }

        // Another process started one in between — use it; anything else means the store refused the write.
        $current = $this->cache->get($key);

        return is_string($current) && $current !== '' ? $current : null;
    }

    private function generationKey(string $group): string
    {
        return $this->prefix.'g:'.hash('sha256', $group);
    }

    private function entryKey(string $group, string $generation, string $key): string
    {
        return $this->prefix.'q:'.hash('sha256', $group."\x1f".$generation."\x1f".$key);
    }

    private function report(string $operation, string $group, Throwable $e): void
    {
        $this->logger?->warning(
            "CQRS query cache {$operation} failed for group [{$group}]; the query runs uncached: {$e->getMessage()}",
            ['exception' => $e],
        );
    }
}
