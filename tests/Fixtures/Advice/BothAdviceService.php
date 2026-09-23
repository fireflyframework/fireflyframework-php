<?php

declare(strict_types=1);

namespace Firefly\Tests\Fixtures\Advice;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Security\Access\Attributes\PreAuthorize;

/**
 * The one shape no other fixture in this repository has: a plain #[Service] — not a handler, not a controller,
 * so no dispatch seam enforces its rules and MethodSecurityScanner::scanProxyAdvice() claims it — carrying
 * #[PreAuthorize] AND #[Transactional] on a single method, which is what makes the generated proxy run two
 * links instead of one.
 *
 * Chapter 9's exercise 3 asks the reader to build exactly this and read the interceptor chain off the emitted
 * proxy; DocsDiagramsTest generates it here through the real ProxyPlanner + ProxyClassGenerator, so the
 * sentence the chapter prints is checked against emitted source rather than remembered. The signature mirrors
 * the chapter's own `TransferService::transfer(int $amount): int` sample for the same reason.
 *
 * Deliberately NOT final: the generated proxy extends it.
 */
#[Service]
class BothAdviceService
{
    #[PreAuthorize("hasRole('ADMIN')")]
    #[Transactional]
    public function transfer(int $amount): int
    {
        return $amount;
    }
}
