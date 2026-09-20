<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\FinalService;

use Firefly\Container\Attributes\Service;
use Firefly\Security\Access\Attributes\PreAuthorize;

/** A final #[Service] with a rule nothing but a proxy could enforce: the scan must refuse it, loudly. */
#[Service]
final class SealedService
{
    #[PreAuthorize("hasRole('ADMIN')")]
    public function secret(): string
    {
        return 'secret';
    }
}
