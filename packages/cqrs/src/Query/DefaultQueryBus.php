<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Query;

use Firefly\Cqrs\Cache\Cacheable;
use Firefly\Cqrs\Cache\GroupedQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Cache\QueryCacheKey;
use Firefly\Cqrs\Cache\QueryCachePolicies;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Exception\QueryProcessingException;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Security\QueryAuthorizer;
use Firefly\Cqrs\Tracing\CqrsTracing;
use Firefly\Cqrs\Tracing\NoOpCqrsTracing;
use Firefly\Cqrs\Validation\MessageValidator;
use Throwable;

/**
 * The synchronous in-process query mediator. ask() runs: correlate -> validate -> authorize -> cache-get (a Cacheable
 * query yielding a non-null key is looked up; a non-null hit short-circuits straight to metrics + return) -> resolve
 * the single registered handler and invoke it -> cache-put -> metrics. The key is the query's own cacheKey() when it
 * is Cacheable, else the key QueryCacheKey derives from the value object when the query carries #[CachedQuery], else
 * there is no caching. A GroupedQueryCache is addressed by the query's group and its policy TTL (falling back to
 * firefly.cqrs.query.cache_ttl); a plain QueryCache keeps receiving get()/put() with the flat key, so an
 * application's own cache keeps working unchanged. The default is still NoOpQueryCache —
 * firefly.cqrs.query.cache.provider=cache binds CacheQueryCache. Any handler/stage throwable is metrics-recorded
 * and re-thrown WRAPPED in a category-preserving QueryProcessingException (unless already one). Correlation is begun
 * and restored in finally, exactly like the command bus. The whole validate → authorize → cache-get → resolve →
 * invoke → cache-put sequence runs inside the CqrsTracing seam (an INTERNAL span when observability is on), so a
 * validation refusal and a cache hit are both visible on the trace; correlation and metrics stay outside it,
 * because they must observe the seam's own failures too, and a cache hit is a success to the metrics either way.
 */
final class DefaultQueryBus implements QueryBus
{
    private readonly CqrsTracing $tracing;

    public function __construct(
        private readonly HandlerRegistry $registry,
        private readonly MessageValidator $validator,
        private readonly QueryAuthorizer $authorizer,
        private readonly CorrelationContext $correlation,
        private readonly CqrsMetrics $metrics,
        private readonly QueryCache $cache,
        private readonly ?int $cacheTtl = null,
        ?CqrsTracing $tracing = null,
        private readonly ?QueryCachePolicies $policies = null,
    ) {
        $this->tracing = $tracing ?? new NoOpCqrsTracing;
    }

    public function ask(object $query): mixed
    {
        $prior = $this->correlation->begin();
        $startedAt = microtime(true);

        try {
            $result = $this->tracing->traceQuery($query, function () use ($query): mixed {
                $this->validator->validate($query);
                $this->authorizer->authorize($query);

                $policy = $this->policies?->policyFor($query::class);
                $key = match (true) {
                    $query instanceof Cacheable => $query->cacheKey(),
                    $policy !== null => QueryCacheKey::of($query),
                    default => null,
                };
                $group = $policy !== null ? $policy->group : '';
                $ttl = $policy->ttl ?? $this->cacheTtl;

                if ($key !== null) {
                    $cached = $this->cache instanceof GroupedQueryCache
                        ? $this->cache->lookup($group, $key)
                        : $this->cache->get($key);
                    if ($cached !== null) {
                        return $cached;
                    }
                }

                $handler = $this->registry->findQueryHandler($query::class);
                $result = $handler($query);

                if ($key !== null) {
                    if ($this->cache instanceof GroupedQueryCache) {
                        $this->cache->store($group, $key, $result, $ttl);
                    } else {
                        $this->cache->put($key, $result, $ttl);
                    }
                }

                return $result;
            });

            $this->metrics->recordQuerySuccess($query, microtime(true) - $startedAt);

            return $result;
        } catch (Throwable $e) {
            $this->metrics->recordQueryFailure($query, microtime(true) - $startedAt);

            throw $e instanceof QueryProcessingException ? $e : new QueryProcessingException($query::class, $e);
        } finally {
            $this->correlation->end($prior);
        }
    }
}
