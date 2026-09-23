<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\ClassLevelFinalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Retry;

/**
 * The same fatal reached the other way: nobody wrote an attribute on the `final` method, the CLASS-LEVEL
 * #[Retry] fanned onto it. Skipping it silently would leave one unguarded method in an otherwise guarded
 * class, so the refusal fires here too — the class DECLARES the final method, so "remove `final`" is an
 * instruction its reader can actually follow.
 */
#[Service]
#[Retry('payments')]
class SealedMethodGateway
{
    public function charge(string $account): string
    {
        return $account;
    }

    final public function sealed(): void {}
}
