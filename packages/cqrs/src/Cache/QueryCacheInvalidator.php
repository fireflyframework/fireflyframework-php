<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

use Firefly\Eda\EventEnvelope;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the compiled #[InvalidatesQuery] rules (plus the firefly.cqrs.query.cache.invalidation config rules) to
 * the query cache. Two entry points, one rule set:
 *
 *   * onEvent() — an object dispatched IN PROCESS (a DomainEvent after its transaction committed). Matched by class
 *     (instanceof, so a rule on a base event covers its subclasses); placeholders read its public properties.
 *   * onEnvelope() — an EDA envelope (another process's event, or this one's echoed by the domain bridge). Matched
 *     by EXACT event type; placeholders read its payload, dotted paths reaching into nested arrays.
 *
 * Evicting twice is harmless, so an event that arrives both ways costs one extra delete. A rule whose placeholder
 * cannot be filled evicts the whole GROUP instead and logs why: an unfillable key would otherwise evict nothing and
 * leave the stale entry serving. With a cache that has no groups (an application's own QueryCache), key evictions
 * go through evict() and group evictions are skipped with one warning.
 *
 * Nothing here ever throws: a failing eviction is logged and the command or consumer that caused it carries on.
 */
final class QueryCacheInvalidator
{
    private bool $warnedGroupless = false;

    /**
     * @param  list<InvalidationRule>  $rules
     */
    public function __construct(
        private readonly QueryCache $cache,
        private readonly QueryCachePolicies $policies,
        private readonly array $rules,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return list<string> the distinct event types the rules match, for one exact EDA subscription each
     */
    public function eventTypes(): array
    {
        return array_values(array_unique(array_map(static fn (InvalidationRule $rule): string => $rule->eventType, $this->rules)));
    }

    public function hasClassRules(): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->eventClass !== '') {
                return true;
            }
        }

        return false;
    }

    public function onEvent(object $event): int
    {
        $values = null;
        $evicted = 0;

        foreach ($this->rules as $rule) {
            if ($rule->eventClass === '' || ! $event instanceof $rule->eventClass) {
                continue;
            }

            $values ??= self::publicProperties($event);
            $evicted += $this->apply($rule, $values, $event::class);
        }

        return $evicted;
    }

    public function onEnvelope(EventEnvelope $envelope): int
    {
        $evicted = 0;

        foreach ($this->rules as $rule) {
            if ($rule->eventType === $envelope->eventType) {
                $evicted += $this->apply($rule, $envelope->payload, $envelope->eventType);
            }
        }

        return $evicted;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function apply(InvalidationRule $rule, array $values, string $source): int
    {
        $group = $this->policies->groupFor($rule->queryClass);

        try {
            $key = $rule->evictsGroup() ? null : $this->keyFor($rule, $values, $source);

            if ($key !== null) {
                if ($this->cache instanceof GroupedQueryCache) {
                    $this->cache->evictKey($group, $key);
                } else {
                    $this->cache->evict($key);
                }

                return 1;
            }

            if ($this->cache instanceof GroupedQueryCache) {
                $this->cache->evictGroup($group);

                return 1;
            }

            if (! $this->warnedGroupless) {
                $this->warnedGroupless = true;
                $this->logger?->warning('The bound QueryCache has no groups, so #[InvalidatesQuery] rules that evict a whole group are skipped; bind a GroupedQueryCache (firefly.cqrs.query.cache.provider=cache).');
            }

            return 0;
        } catch (Throwable $e) {
            $this->logger?->warning("Query cache invalidation for [{$rule->queryClass}] on [{$source}] failed: {$e->getMessage()}", ['exception' => $e]);

            return 0;
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function keyFor(InvalidationRule $rule, array $values, string $source): ?string
    {
        if ($rule->key !== null) {
            $missing = null;
            $key = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_.]*)\}/', static function (array $match) use ($values, &$missing): string {
                $value = self::path($values, $match[1]);
                if (! is_scalar($value)) {
                    $missing ??= $match[1];

                    return $match[0];
                }

                return (string) $value;
            }, $rule->key);

            if ($missing !== null || ! is_string($key)) {
                $this->logger?->warning("[{$source}] has no scalar `{$missing}` for the key template '{$rule->key}' of [{$rule->queryClass}]; evicting the whole group instead.");

                return null;
            }

            return $key;
        }

        $properties = [];
        foreach ($rule->with as $queryProperty => $eventProperty) {
            if (! array_key_exists($eventProperty, $values)) {
                $this->logger?->warning("[{$source}] has no `{$eventProperty}` for the derived key of [{$rule->queryClass}]; evicting the whole group instead.");

                return null;
            }
            $properties[$queryProperty] = $values[$eventProperty];
        }

        return QueryCacheKey::fromProperties($rule->queryClass, $properties);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function path(array $values, string $path): mixed
    {
        $current = $values;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    private static function publicProperties(object $event): array
    {
        $properties = [];
        foreach (get_object_vars($event) as $name => $value) {
            $properties[(string) $name] = $value;
        }

        return $properties;
    }
}
