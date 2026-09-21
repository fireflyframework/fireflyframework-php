<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Container\Attributes\Service;
use Firefly\Security\Access\Attributes\PreAuthorize;

/**
 * A #[Service] whose only rule is method security — no #[Transactional] — so it appears in proxy-plan.php
 * because of firefly/security's advice source alone. NOT final: the proxy extends it.
 */
#[Service]
class DemoSecuredService
{
    #[PreAuthorize("hasRole('ADMIN')")]
    public function secret(): string
    {
        return 'secret';
    }
}
