<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use DateTimeImmutable;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Event\ApplicationReadyEvent;
use Firefly\Context\Event\ContextRefreshedEvent;
use Firefly\Context\Event\DispatcherEventPublisher;
use Illuminate\Container\Container;

/**
 * The final boot phase: publishes ContextRefreshedEvent, then ApplicationReadyEvent — Spring's
 * ContextRefreshedEvent/ApplicationReadyEvent pair, in the same order Spring Boot fires them.
 *
 * Both events go through the ApplicationEventPublisher PORT exactly like application code would
 * (via ApplicationContext::publishEvent()), so any #[AsEventListener] registered at phase 800
 * observes them the same way it would observe any other application event. Resolved from the
 * container when FireflyServiceProvider's binding exists (see its docblock) — never `new`-ed
 * unconditionally — so that binding is load-bearing here too, not just inside
 * FireflyKernel::buildApplicationContext(). Falls back to a fresh DispatcherEventPublisher only for
 * a partial kernel assembled without FireflyServiceProvider at all (e.g. a focused unit test).
 */
final class ContextRefreshedPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::ContextRefreshed;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $publisher = $this->resolvePublisher($context->container);
        $now = new DateTimeImmutable;

        $publisher->publish(new ContextRefreshedEvent($now));
        $publisher->publish(new ApplicationReadyEvent($now));
    }

    private function resolvePublisher(Container $container): ApplicationEventPublisher
    {
        if ($container->bound(ApplicationEventPublisher::class)) {
            /** @var ApplicationEventPublisher */
            return $container->make(ApplicationEventPublisher::class);
        }

        return new DispatcherEventPublisher($container);
    }
}
