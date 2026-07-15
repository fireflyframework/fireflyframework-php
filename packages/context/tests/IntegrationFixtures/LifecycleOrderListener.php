<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Event\ApplicationReadyEvent;
use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Event\ContextRefreshedEvent;

/**
 * Listens for BOTH of ContextRefreshedPass's published events, so IntegrationTest can prove they
 * are received in the same order Spring Boot fires them: ContextRefreshedEvent, then
 * ApplicationReadyEvent.
 */
#[Component]
final class LifecycleOrderListener
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    #[AsEventListener]
    public function onRefreshed(ContextRefreshedEvent $event): void
    {
        $this->recorder->record('listener:refreshed');
    }

    #[AsEventListener]
    public function onReady(ApplicationReadyEvent $event): void
    {
        $this->recorder->record('listener:ready');
    }
}
