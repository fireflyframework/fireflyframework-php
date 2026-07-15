<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

/**
 * A plain, non-#[Component] value class. Deliberately NOT scanned/registered: it exists solely to
 * be auto-wired by Illuminate\Container\Container when #[PostConstruct]'s invoker calls
 * $container->call([$bean, $method]) — proving init-method parameters get real dependency
 * injection, not merely a zero-argument invocation.
 */
final class Greeting
{
    public function __construct(public string $text = 'hello') {}
}
