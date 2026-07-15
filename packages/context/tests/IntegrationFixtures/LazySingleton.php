<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;

/**
 * Marked #[Lazy] — EagerSingletonsPass must skip it entirely, so its constructor never runs during
 * boot. IntegrationTest asserts the recorder never observes 'lazy:instantiated'.
 */
#[Component]
#[Lazy]
final class LazySingleton
{
    public function __construct(WidgetRecorder $recorder)
    {
        $recorder->record('lazy:instantiated');
    }
}
