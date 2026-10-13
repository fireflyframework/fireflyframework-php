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
 * evictGroup() replaces the generation with an eviction marker; the next lookup starts a fresh random one, and no
 * entry written under an older generation is reachable again — they expire on their own TTL (which is why the module
 * guide tells you to set one). The marker is what narrows the classic read/evict race: a handler that looked the
 * group up before a commit and stores after the commit's eviction finds the marker, not a generation, and its store
 * is dropped, so the stale result it read is never cached. Only store() is held back by the marker; a store into a
 * group that never had a generation (a put() with no get() before it) starts one, as it always has. What remains is
 * the window in which ANOTHER lookup starts a new generation between the eviction and the late store: the store
 * then writes its stale result under the new generation. Closing that fully needs a token handed from lookup() to
 * store(), which the QueryCache contract does not carry. A single-key eviction (evictKey) has none of this; it is
 * the cheaper, narrower tool.
 *
 * A first generation is created with add() and a fixed TTL: Laravel's Repository::add() is only atomic when it is
 * given a TTL, and two processes starting a generation at once must agree on one. A generation that replaces an
 * eviction marker is put() and then read back, so concurrent lookups settle on whichever write landed last; a
 * lookup that lost that race only costs a miss. Keys are hashed, so a group or key of any
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

    /** The value evictGroup() leaves in place of a generation; never a valid generation (those are 16 hex digits). */
    private const string EVICTED = '-';

    private const int EXISTING = 0;

    private const int FOR_STORE = 1;

    private const int FOR_LOOKUP = 2;

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
            $generation = $this->generation($group, self::FOR_LOOKUP);

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
            $generation = $this->generation($group, self::FOR_STORE);
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
            $generation = $this->generation($group, self::EXISTING);
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
            $this->cache->put($this->generationKey($group), self::EVICTED, self::GENERATION_TTL);
        } catch (Throwable $e) {
            $this->report('evictGroup', $group, $e);
        }
    }

    /**
     * The group's current generation, or null when there is none to use.
     *
     * EXISTING never starts one. FOR_STORE starts one only for a group that never had one, and answers null while
     * an eviction marker stands. FOR_LOOKUP starts one in either case.
     *
     * @param  self::EXISTING|self::FOR_STORE|self::FOR_LOOKUP  $mode
     */
    private function generation(string $group, int $mode): ?string
    {
        $key = $this->generationKey($group);
        $current = $this->cache->get($key);
        if (is_string($current) && $current !== '' && $current !== self::EVICTED) {
            return $current;
        }

        $evicted = $current === self::EVICTED;
        if ($mode === self::EXISTING || ($evicted && $mode === self::FOR_STORE)) {
            return null;
        }

        $fresh = bin2hex(random_bytes(8));
        if ($evicted) {
            $this->cache->put($key, $fresh, self::GENERATION_TTL);
        } elseif ($this->cache->add($key, $fresh, self::GENERATION_TTL)) {
            return $fresh;
        }

        // Read back what landed: another process may have started a generation in between, or evicted again, or the
        // store refused the write. Only a real generation is usable.
        $current = $this->cache->get($key);

        return is_string($current) && $current !== '' && $current !== self::EVICTED ? $current : null;
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
