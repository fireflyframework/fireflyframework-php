<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Cache;

use Firefly\Cqrs\Handler\HandlerManifest;

/**
 * The compiled #[CachedQuery] policies, looked up by query class. groupFor() is the one rule the bus and the
 * invalidator must agree on: a query with a policy lives in its policy's group, anything else (a legacy Cacheable
 * query) in the default group ''.
 */
final class QueryCachePolicies
{
    /**
     * @param  array<string, CachedQueryPolicy>  $policies
     */
    public function __construct(private readonly array $policies = []) {}

    public static function fromManifest(HandlerManifest $manifest): self
    {
        return new self($manifest->cachedQueries());
    }

    public function policyFor(string $queryClass): ?CachedQueryPolicy
    {
        return $this->policies[$queryClass] ?? null;
    }

    public function groupFor(string $queryClass): string
    {
        return $this->policyFor($queryClass)->group ?? '';
    }
}
