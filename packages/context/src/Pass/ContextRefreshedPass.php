<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use DateTimeImmutable;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\ApplicationReadyEvent;
use Firefly\Context\Event\ContextRefreshedEvent;
use Firefly\Context\Event\DispatcherEventPublisher;

/**
 * The final boot phase: publishes ContextRefreshedEvent, then ApplicationReadyEvent — Spring's
 * ContextRefreshedEvent/ApplicationReadyEvent pair, in the same order Spring Boot fires them.
 *
 * Both events go through DispatcherEventPublisher exactly like application code would (via
 * ApplicationContext::publishEvent()), so any #[AsEventListener] registered at phase 800 observes
 * them the same way it would observe any other application event.
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
        $publisher = new DispatcherEventPublisher($context->container);
        $now = new DateTimeImmutable;

        $publisher->publish(new ContextRefreshedEvent($now));
        $publisher->publish(new ApplicationReadyEvent($now));
    }
}
