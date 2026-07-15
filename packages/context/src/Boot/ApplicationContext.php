<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

use DateTimeImmutable;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Event\ContextClosedEvent;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\LifecycleRegistry;

/**
 * The public boot-engine facade — Spring's ApplicationContext, ported. Application code that needs
 * to reach into the container by hand (rather than via constructor injection) programs to THIS
 * class, never directly to Illuminate\Container\Container or Firefly\Container\Container.
 *
 * get()/getByName()/getAll()/has() delegate entirely to the Firefly\Container\Container facade —
 * see its own docblock for getAll()'s #[Order] semantics. publishEvent() delegates to the
 * ApplicationEventPublisher port, exactly like any #[AsEventListener]-observable application event.
 *
 * close() is idempotent: a second call is a safe no-op, mirroring Spring's
 * ConfigurableApplicationContext#close().
 */
final class ApplicationContext
{
    private bool $active = true;

    public function __construct(
        private readonly FireflyContainer $container,
        private readonly ApplicationEventPublisher $publisher,
        private readonly DisposableBeanRegistry $disposables,
        private readonly LifecycleRegistry $lifecycles,
    ) {}

    public function get(string $type): object
    {
        return $this->container->get($type);
    }

    public function getByName(string $name): object
    {
        return $this->container->getByName($name);
    }

    /**
     * @return list<object>
     */
    public function getAll(string $interface): array
    {
        return $this->container->getAll($interface);
    }

    public function has(string $type): bool
    {
        return $this->container->has($type);
    }

    public function publishEvent(object $event): void
    {
        $this->publisher->publish($event);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Shuts the context down: publishes ContextClosedEvent, then runs every tracked #[PreDestroy]
     * (Singleton-scope) callback and every Lifecycle::stop() in REVERSE order, then marks the
     * context inactive. Idempotent — a second call does nothing.
     */
    public function close(): void
    {
        if (! $this->active) {
            return;
        }

        $this->publisher->publish(new ContextClosedEvent(new DateTimeImmutable));

        // DELIBERATE ORDER, pinned by ApplicationContextTest's ['destroy:second', 'destroy:first',
        // 'stop:B', 'stop:A'] assertion — #[PreDestroy] singletons drain BEFORE Lifecycle::stop().
        // Spring's AbstractApplicationContext.doClose() does the reverse (stop Lifecycle beans, THEN
        // destroy singletons — see its own javadoc: "to avoid delays during individual destruction").
        // We invert it because this pipeline's own phase order already runs singletons down first:
        // InfrastructureStart (Lifecycle::start()) is phase 850, EagerSingletons is phase 900, so
        // "singletons down first, then Lifecycle" is reverse-of-startup FOR THIS PIPELINE, even
        // though it differs from Spring's own rule (M4 review #4, Minor — recorded here explicitly so
        // this reads as a considered choice rather than an accident a future maintainer "corrects").
        // Known narrow hazard: a bean that is BOTH a Lifecycle AND declares #[PreDestroy] gets
        // #[PreDestroy] run before its own stop() — inverted for that one object. Not changing it.
        $this->disposables->drainSingletons();
        $this->lifecycles->stopAll();

        $this->active = false;
    }
}
