<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

/**
 * Deliberately NOT #[Component]: this class has no manifest entry of its own — it is created at
 * runtime by ProxyingBpp::afterInitialization(), standing in for what M9 (#[Transactional]) / M12
 * (#[PreAuthorize]) will one day generate as a real interception proxy.
 *
 * EXTENDS PostConstructWidget (never composes it behind __call()): $container->call() reflects on
 * the resolved object to invoke #[PreDestroy]'s shutdown(), and ReflectionMethod cannot see a
 * method that only exists via __call() magic. Real inheritance keeps shutdown() (and init())
 * reflectable, so the framework's lifecycle invoker keeps working against a proxy exactly as it
 * would against the plain instance.
 */
final class PostConstructWidgetProxy extends PostConstructWidget
{
    public function __construct(PostConstructWidget $inner)
    {
        parent::__construct($inner->recorder());
    }
}
