<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Context\Lifecycle\PostConstruct;

/**
 * An eager singleton whose #[PostConstruct] publishes DuringEagerEvent — exercised the moment
 * EagerSingletonsPass resolves it (phase 900). Programs against the ApplicationEventPublisher PORT
 * (never the concrete DispatcherEventPublisher adapter), exactly as real application code should.
 */
#[Component]
final class DuringEagerPublisher
{
    public function __construct(private readonly ApplicationEventPublisher $publisher) {}

    #[PostConstruct]
    public function announce(): void
    {
        $this->publisher->publish(new DuringEagerEvent('from-post-construct'));
    }
}
