<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

/**
 * The same two stacks, the same `when-authorized` configuration, and the security master flag OFF.
 *
 * This is the half of the port's contract that the CHANGELOG's migration line and both module documents
 * promise and that nothing else exercises: an application with `firefly/security` in its vendor directory
 * but `firefly.security.enabled` false must keep `firefly/actuator`'s DenyHealthDetailsAuthorizer and answer
 * exactly what it answered before the port existed. Everything about the deployment that could tempt a
 * disclosure is left switched on — `show-details` is still `when-authorized` and the roles list is still
 * empty, which with the flag ON is the widest setting there is ("any authenticated principal") — so the only
 * thing standing between this request and the component details is the conditional on the filler bean.
 *
 * The URL rules are dropped along with the flag rather than kept: `firefly.security.http.enabled` requires
 * the master flag, so leaving rules configured here would describe a deployment that cannot exist and invite
 * a reader to think they were what withheld the details.
 */
abstract class SecurityOffHealthCapstoneTestCase extends SecuredHealthCapstoneTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function securityOverrides(): array
    {
        return [
            ...parent::securityOverrides(),
            'firefly.security.enabled' => false,
            'firefly.security.http.enabled' => false,
            'firefly.security.http_basic.enabled' => false,
            'firefly.security.http.rules' => [],
        ];
    }
}
