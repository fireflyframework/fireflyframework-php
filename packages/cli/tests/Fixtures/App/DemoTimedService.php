<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * A #[Service] whose only rule is a method metric — no #[Transactional], no #[PreAuthorize] — so it appears
 * in proxy-plan.php because of firefly/observability's advice source alone, exactly as DemoSecuredService
 * does for firefly/security's. NOT final: the proxy extends it.
 *
 * It exists because the cached path is the one an unexercised AdviceSource::render() fails on: the method
 * emits PHP source into proxy-plan.php and into the generated proxy, and a wrong literal there is a parse
 * error that appears in production and never in dev.
 */
#[Service]
class DemoTimedService
{
    #[Timed('demo.timed')]
    public function measured(): string
    {
        return 'measured';
    }
}
