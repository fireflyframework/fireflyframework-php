<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Query;

use Firefly\Cqrs\Cache\Cacheable;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Exception\QueryProcessingException;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Security\QueryAuthorizer;
use Firefly\Cqrs\Validation\MessageValidator;
use Throwable;

/**
 * The synchronous in-process query mediator. ask() runs: correlate -> validate -> authorize -> cache-get (a Cacheable
 * query yielding a non-null key is looked up; a non-null hit short-circuits straight to metrics + return) -> resolve
 * the single registered handler and invoke it -> cache-put -> metrics. The QueryCache default is NoOpQueryCache
 * (always-miss), so caching is an inert seam until firefly/cache lands. Any handler/stage throwable is metrics-recorded
 * and re-thrown WRAPPED in a category-preserving QueryProcessingException (unless already one). Correlation is begun
 * and restored in finally, exactly like the command bus.
 */
final class DefaultQueryBus implements QueryBus
{
    public function __construct(
        private readonly HandlerRegistry $registry,
        private readonly MessageValidator $validator,
        private readonly QueryAuthorizer $authorizer,
        private readonly CorrelationContext $correlation,
        private readonly CqrsMetrics $metrics,
        private readonly QueryCache $cache,
        private readonly ?int $cacheTtl = null,
    ) {}

    public function ask(object $query): mixed
    {
        $prior = $this->correlation->begin();
        $startedAt = microtime(true);

        try {
            $this->validator->validate($query);
            $this->authorizer->authorize($query);

            $key = $query instanceof Cacheable ? $query->cacheKey() : null;

            if ($key !== null) {
                $cached = $this->cache->get($key);
                if ($cached !== null) {
                    $this->metrics->recordQuerySuccess($query, microtime(true) - $startedAt);

                    return $cached;
                }
            }

            $handler = $this->registry->findQueryHandler($query::class);
            $result = $handler($query);

            if ($key !== null) {
                $this->cache->put($key, $result, $this->cacheTtl);
            }

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
