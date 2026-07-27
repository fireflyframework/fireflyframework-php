<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Container\Attributes\Service;

/** A plain #[Service] — the DI-resolution capability of the foundation flow. Mirrors the T3 App\DemoService. */
#[Service]
final class FoundationService
{
    public function greet(): string
    {
        return 'hello-foundation';
    }
}
