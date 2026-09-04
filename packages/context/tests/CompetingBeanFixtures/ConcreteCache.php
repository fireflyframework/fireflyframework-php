<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * The OTHER competing shape: two #[Bean] methods whose declared return type is this CONCRETE class
 * rather than an interface. The distinction is not cosmetic — it decides WHICH pass is responsible
 * for the #[AsEventListener] below:
 *
 *  - concrete return (this class): ContextScanner DOES scan it, so
 *    RegisterEventListenersPass's boot-time sweep finds the listener and owns registration.
 *  - interface return (CachePort): unscanned, so the sweep is structurally blind and
 *    RegisterBeanPostProcessorsPass's late-bound recovery owns it instead.
 *
 * Both shapes have to attach the listener once per competing BEAN — the sweep used to attach it
 * once per TYPE, invoking through the contested type key, which resolves to the #[Primary] winner
 * (or, with no #[Primary], to a factory that throws) rather than to each bean in turn.
 *
 * $tag is a constructor string, so this class is never auto-wirable and can only ever come from
 * the #[Bean] methods on ConcreteCacheConfiguration — exactly the point.
 */
final class ConcreteCache
{
    public function __construct(private readonly CacheProbe $probe, private readonly string $tag)
    {
        $this->probe->record("construct:{$this->tag}");
    }

    public function tag(): string
    {
        return $this->tag;
    }

    #[AsEventListener]
    public function onPing(CachePing $event): void
    {
        $this->probe->record("listener:{$this->tag}");
    }
}
