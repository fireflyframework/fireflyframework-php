<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

/**
 * The same boot with the master flag ON and `firefly.security.method.enabled` OFF: every other security bean
 * exists, only the proxy link's #[Bean] is conditioned away, and the planned proxy must run a pass-through in its
 * place.
 */
abstract class ProxiedMethodSecurityMethodOffBootTestCase extends ProxiedMethodSecurityBootTestCase
{
    /** @return array<string, mixed> */
    protected function security(): array
    {
        return ['enabled' => true, 'method' => ['enabled' => false]];
    }
}
