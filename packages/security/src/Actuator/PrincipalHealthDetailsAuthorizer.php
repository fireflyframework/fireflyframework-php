<?php

declare(strict_types=1);

namespace Firefly\Security\Actuator;

use Firefly\Actuator\Health\HealthDetailsAuthorizer;
use Firefly\Config\Config;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\SecurityContextHolder;

/**
 * firefly/security's answer to firefly/actuator's question: may THIS caller read health details.
 *
 * Spring's rule, ported. `management.endpoint.health.roles` empty means "any authenticated principal";
 * a non-empty list means "an authenticated principal holding at least one of these roles". The list is read
 * live, the principal comes from the same SecurityContextHolder every other rule in this package reads, and
 * the comparison goes through RoleHierarchy so `ROLE_ADMIN > ROLE_ACTUATOR` grants what it says it grants.
 *
 * A `ROLE_` prefix is added to a bare name, because that is how the rest of this framework spells a role
 * (`hasRole:ADMIN` means ROLE_ADMIN) and an operator writing `roles: [ADMIN]` in the management block should
 * not get a silently different answer from the one the URL rule two files away gives.
 *
 * THE MASTER FLAG IS CHECKED FIRST AND IS NOT A FORMALITY. With `firefly.security.enabled` off, the auth
 * filters do not run, nothing ever populates the holder, and every caller would be "anonymous" — which would
 * be correct but slow. More importantly, a deployment that turned security off has no principal model at
 * all, and answering anything but `false` there would mean the same configuration means different things
 * depending on a flag nobody reading the management block can see.
 */
final class PrincipalHealthDetailsAuthorizer implements HealthDetailsAuthorizer
{
    public function __construct(
        private readonly Config $config,
        private readonly RoleHierarchy $roles,
    ) {}

    public function mayReadDetails(): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false)) {
            return false;
        }

        $authentication = SecurityContextHolder::getAuthentication();
        if ($authentication === null || ! $authentication->isAuthenticated()) {
            return false;
        }

        $required = $this->required();
        if ($required === []) {
            return true;
        }

        $held = $this->roles->reachableAuthorities($authentication->authorityStrings());

        return array_intersect($required, $held) !== [];
    }

    /**
     * The configured roles, normalised to the `ROLE_` spelling and de-duplicated. The key is spelled in full
     * at the read site rather than hidden behind a constant, so tests/ConfigReferenceTest can see it and hold
     * skeleton/config/firefly.php to documenting it.
     *
     * @return list<string>
     */
    private function required(): array
    {
        $configured = $this->config->array('firefly.management.endpoint.health.roles', []);

        $roles = [];
        foreach ($configured as $role) {
            if (! is_string($role) || trim($role) === '') {
                continue;
            }
            $role = trim($role);
            $roles[] = str_starts_with($role, 'ROLE_') ? $role : 'ROLE_'.$role;
        }

        return array_values(array_unique($roles));
    }
}
