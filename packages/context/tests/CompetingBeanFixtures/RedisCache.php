<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Lifecycle\PostConstruct;

/**
 * The NON-#[Primary] half of the competing pair. Deliberately NOT a #[Component]: it exists ONLY as a
 * #[Bean] factory product, so ComponentScanner never sees it and the only thing the container
 * knows about it is the key ContainerRegistrar bound its factory under ('redisCache').
 * ContextScanner still scans it — it scans every concrete class in a root, component or not —
 * which is why its #[PostConstruct] and #[AsEventListener] below are discoverable at all.
 */
final class RedisCache implements CachePort
{
    public function __construct(private readonly CacheProbe $probe)
    {
        $this->probe->record('construct:redis');
    }

    public function name(): string
    {
        return 'redis';
    }

    #[PostConstruct]
    public function warm(): void
    {
        $this->probe->record('postConstruct:redis');
    }

    #[AsEventListener]
    public function onPing(CachePing $event): void
    {
        $this->probe->record('listener:redis');
    }
}
