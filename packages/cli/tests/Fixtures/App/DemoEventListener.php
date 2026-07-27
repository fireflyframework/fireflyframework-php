<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Eda\Attributes\EventListener;

final class DemoEventListener
{
    #[EventListener('demo.*')]
    public function onDemo(DemoEvent $event): void
    {
        // no-op fixture
    }
}
