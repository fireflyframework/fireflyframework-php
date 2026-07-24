<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\Cacheable;
use Firefly\Cqrs\Cache\NoOpQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Exception\QueryProcessingException;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Query\DefaultQueryBus;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Security\QueryAuthorizer;
use Firefly\Cqrs\Validation\MessageValidator;
use Firefly\Cqrs\Validation\Validatable;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Validation\Validator;

/**
 * A QueryCache double: a preset get() value + a record of put() calls.
 *
 * @return QueryCache&object{puts: list<array{key: string, value: mixed}>}
 */
function recordingCache(mixed $hit = null): QueryCache
{
    return new class($hit) implements QueryCache
    {
        /** @var list<array{key: string, value: mixed}> */
        public array $puts = [];

        public function __construct(private readonly mixed $hit) {}

        public function get(string $key): mixed
        {
            return $this->hit;
        }

        public function put(string $key, mixed $value, ?int $ttl): void
        {
            $this->puts[] = ['key' => $key, 'value' => $value];
        }

        public function evict(string $key): void {}
    };
}

/** A Cacheable query with a fixed key. */
final class FindThing implements Cacheable
{
    public function __construct(private readonly ?string $key = 'thing:1') {}

    public function cacheKey(): ?string
    {
        return $this->key;
    }
}

/** A query that opts into validation, so MessageValidator actually calls the bound Validator. */
final class ValidatedQuery implements Validatable
{
    public function validationData(): array
    {
        return ['name' => ''];
    }

    public function validationRules(): array
    {
        return ['name' => 'required'];
    }
}

/** Build a query bus around a registry + a cache + a metrics recorder (a no-op validator, allow-all authorizer). */
function queryBus(HandlerRegistry $registry, QueryCache $cache, CqrsMetrics $metrics, ?QueryAuthorizer $authorizer = null, ?CorrelationContext $correlation = null): DefaultQueryBus
{
    return new DefaultQueryBus(
        $registry,
        new MessageValidator(null),
        $authorizer ?? new AllowAllAuthorizer,
        $correlation ?? new CorrelationContext,
        $metrics,
        $cache,
        60,
    );
}

it('runs the handler on a cache MISS, returns its result, caches it, records success, and restores correlation to null', function () {
    $registry = new HandlerRegistry;
    $ran = 0;
    $correlation = new CorrelationContext;
    $seenId = 'unset';
    $registry->registerQueryHandler(FindThing::class, function () use (&$ran, $correlation, &$seenId): string {
        $ran++;
        $seenId = $correlation->currentId(); // an id is active DURING the handler

        return 'result';
    });
    $cache = recordingCache(null); // miss
    $metrics = recordingMetrics(); // from DefaultCommandBusTest's helper (shared Pest scope)

    $result = queryBus($registry, $cache, $metrics, correlation: $correlation)->ask(new FindThing);

    expect($result)->toBe('result')
        ->and($ran)->toBe(1)
        ->and($seenId)->not->toBeNull()               // correlation active during dispatch
        ->and($correlation->currentId())->toBeNull()   // restored after
        ->and($cache->puts)->toBe([['key' => 'thing:1', 'value' => 'result']])
        ->and($metrics->events)->toBe(['query.success']);
});

it('short-circuits to the cached value on a HIT without invoking the handler', function () {
    $registry = new HandlerRegistry;
    $ran = 0;
    $registry->registerQueryHandler(FindThing::class, function () use (&$ran): string {
        $ran++;

        return 'fresh';
    });
    $cache = recordingCache('cached'); // hit

    $result = queryBus($registry, $cache, recordingMetrics())->ask(new FindThing);

    expect($result)->toBe('cached')
        ->and($ran)->toBe(0)          // handler NOT invoked
        ->and($cache->puts)->toBe([]); // nothing re-cached on a hit
});

it('never consults the cache for a non-Cacheable query (NoOpQueryCache path)', function () {
    $registry = new HandlerRegistry;
    $registry->registerQueryHandler('stdClass', fn (): string => 'plain');

    $result = queryBus($registry, new NoOpQueryCache, recordingMetrics())->ask(new stdClass);

    expect($result)->toBe('plain'); // no key -> handler always runs, no cache interaction
});

it('runs validate BEFORE the handler and does not invoke the handler when validation fails', function () {
    $registry = new HandlerRegistry;
    $ran = false;
    $registry->registerQueryHandler(ValidatedQuery::class, function () use (&$ran): string {
        $ran = true;

        return 'result';
    });

    // A Validator that rejects like the shipped one on invalid data; the query is Validatable so validate() fires.
    $rejecting = new class implements Validator
    {
        public function validate(array $data, array $rules): array
        {
            throw new ValidationException('invalid');
        }
    };

    $bus = new DefaultQueryBus(
        $registry,
        new MessageValidator($rejecting),
        new AllowAllAuthorizer,
        new CorrelationContext,
        recordingMetrics(),
        recordingCache(null),
        60,
    );

    try {
        $bus->ask(new ValidatedQuery);
        expect(false)->toBeTrue('expected a QueryProcessingException');
    } catch (QueryProcessingException $e) {
        expect($e->httpStatus())->toBe(422)              // ValidationException 422 preserved
            ->and($ran)->toBeFalse();                     // handler never ran — validate is upstream of dispatch
    }
});

it('runs authorize BEFORE the handler and does not invoke the handler when query authorization denies', function () {
    $registry = new HandlerRegistry;
    $ran = false;
    $registry->registerQueryHandler(FindThing::class, function () use (&$ran): string {
        $ran = true;

        return 'result';
    });

    $denying = new class implements QueryAuthorizer
    {
        public function authorize(object $query): void
        {
            throw new AuthorizationException('nope');
        }
    };

    // A cache HIT — if authorize ran AFTER cache-get, the hit would short-circuit past the deny entirely.
    $cache = recordingCache('cached-value');

    try {
        queryBus($registry, $cache, recordingMetrics(), $denying)->ask(new FindThing);
        expect(false)->toBeTrue('expected a QueryProcessingException');
    } catch (QueryProcessingException $e) {
        expect($e->httpStatus())->toBe(403)   // AuthorizationException category preserved through the wrapper
            ->and($ran)->toBeFalse();          // handler never ran — authorize is upstream of the cache + handler
    }
});

it('wraps a handler throwable in QueryProcessingException, preserves the framework 500, records failure, and restores correlation', function () {
    $registry = new HandlerRegistry;
    $registry->registerQueryHandler(FindThing::class, function (): void {
        throw new RuntimeException('boom');
    });
    $metrics = recordingMetrics();
    $correlation = new CorrelationContext;

    try {
        queryBus($registry, recordingCache(null), $metrics, correlation: $correlation)->ask(new FindThing);
        expect(false)->toBeTrue('expected a QueryProcessingException');
    } catch (QueryProcessingException $e) {
        expect($e->httpStatus())->toBe(500)
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }

    expect($metrics->events)->toBe(['query.failure'])
        ->and($correlation->currentId())->toBeNull(); // finally restores correlation even on throw
});

it('re-throws an already-QueryProcessingException AS-IS (no double wrap)', function () {
    $inner = new QueryProcessingException('App\Inner', new RuntimeException('x'));
    $registry = new HandlerRegistry;
    $registry->registerQueryHandler(FindThing::class, function () use ($inner): void {
        throw $inner;
    });

    try {
        queryBus($registry, recordingCache(null), recordingMetrics())->ask(new FindThing);
        expect(false)->toBeTrue('expected the inner exception');
    } catch (QueryProcessingException $e) {
        expect($e)->toBe($inner)                          // same instance — not re-wrapped
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});
