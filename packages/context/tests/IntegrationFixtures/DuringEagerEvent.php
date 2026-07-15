<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

/**
 * Published by DuringEagerPublisher's #[PostConstruct], DURING EagerSingletonsPass's (phase 900)
 * sweep. Received only if RegisterEventListenersPass (phase 800) already ran — proving the
 * BeanPostProcessors(700)/EventListeners(800)/EagerSingletons(900) phase ordering end-to-end.
 */
final class DuringEagerEvent
{
    public function __construct(public string $note) {}
}
