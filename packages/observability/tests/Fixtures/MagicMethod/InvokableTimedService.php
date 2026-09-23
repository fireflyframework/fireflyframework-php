<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\MagicMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * The single-action-service shape, which is mainstream: one #[Service], one `__invoke()`. The attribute is
 * written HERE, by hand, about THIS method — so dropping it is not a skip, it is the silent no-op this scan
 * exists to refuse. (The class-level fan-out reaching `__invoke` is a different case and stays silent; see
 * Method/ClassLevelService.)
 */
#[Service]
class InvokableTimedService
{
    #[Timed('email.send')]
    public function __invoke(): void {}
}
