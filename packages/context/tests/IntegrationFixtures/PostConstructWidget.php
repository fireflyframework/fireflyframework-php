<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Lifecycle\PreDestroy;

/**
 * Deliberately NOT `final`: PostConstructWidgetProxy below must EXTEND this class (the framework's
 * proxy contract — see ProxyingBpp's docblock), never compose it behind __call().
 *
 * #[PostConstruct]'s init() takes a Greeting parameter to prove init-method DI; #[PreDestroy]'s
 * shutdown() must still fire via the DECLARED class (this class) even after ProxyingBpp has
 * replaced the cached singleton with a PostConstructWidgetProxy instance.
 */
#[Component]
class PostConstructWidget
{
    protected WidgetRecorder $recorder;

    public function __construct(WidgetRecorder $recorder)
    {
        $this->recorder = $recorder;
    }

    public function recorder(): WidgetRecorder
    {
        return $this->recorder;
    }

    #[PostConstruct]
    public function init(Greeting $greeting): void
    {
        $this->recorder->record("postConstruct:{$greeting->text}");
    }

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->recorder->record('preDestroy:PostConstructWidget');
    }
}
