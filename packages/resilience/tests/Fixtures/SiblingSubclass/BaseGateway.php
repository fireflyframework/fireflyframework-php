<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\SiblingSubclass;

use Firefly\Resilience\Method\Retry;

/** The one annotated class in this directory, and the only one whose author could act on a refusal. */
class BaseGateway
{
    #[Retry('payments')]
    public function charge(string $account): string
    {
        return $account;
    }
}
