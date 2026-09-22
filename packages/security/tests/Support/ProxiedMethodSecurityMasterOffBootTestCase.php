<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

/**
 * The same boot with the master flag OFF: no security bean exists at all — not the interceptor, not the
 * evaluator it needs, not the event publisher the evaluator needs — and the planned proxy must run a pass-through
 * in the link's place, exactly as the "annotations are inert until security is enabled" rule promises.
 */
abstract class ProxiedMethodSecurityMasterOffBootTestCase extends ProxiedMethodSecurityBootTestCase
{
    /** @return array<string, mixed> */
    protected function security(): array
    {
        return ['enabled' => false];
    }
}
