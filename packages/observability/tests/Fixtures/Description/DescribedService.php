<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Description;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * Micrometer's `description:` written out of habit. Micrometer carries it to the `# HELP` line; this
 * package's exposition synthesises that line from the meter name and its type, so the scan refuses the
 * parameter instead of compiling it into a descriptor row nothing would read.
 */
#[Service]
class DescribedService
{
    #[Timed('d', description: 'Places an order.')]
    public function described(): void {}
}
