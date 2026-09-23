<?php

declare(strict_types=1);

namespace Firefly\Security\Actuator;

use Firefly\Actuator\Health\HealthDetailsAuthorizer;
use Firefly\Config\Config;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\SecurityContextHolder;
use Psr\Log\LoggerInterface;

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
 * AN UNUSABLE LIST IS NOT AN EMPTY ONE. `roles: []` and `roles: ['']` look alike after normalisation and
 * mean opposite things: the first is the documented "any authenticated principal", the second is an
 * operator who sat down to RESTRICT this surface and mistyped. Reading the second as the first would widen
 * a restriction to the maximum at the exact moment its author believed they had narrowed it — the one
 * direction this class refuses to fail in, and the direction HealthEndpoint::showDetails() already refuses
 * for the value beside it ("anything else — including a typo — is `never`"). So a list that was written and
 * survived normalisation as nothing refuses everybody, and says so once in the log, because a deny nobody
 * can explain is its own kind of outage.
 *
 * THE MASTER FLAG IS CHECKED FIRST AND IS NOT A FORMALITY. With `firefly.security.enabled` off, the auth
 * filters do not run, nothing ever populates the holder, and every caller would be "anonymous" — which would
 * be correct but slow. More importantly, a deployment that turned security off has no principal model at
 * all, and answering anything but `false` there would mean the same configuration means different things
 * depending on a flag nobody reading the management block can see.
 */
final class PrincipalHealthDetailsAuthorizer implements HealthDetailsAuthorizer
{
    /**
     * One warning per boot, not one per scrape. /actuator/health is polled by a liveness probe on a timer,
     * and a misconfiguration that logged on every poll would bury itself in the noise it makes.
     */
    private bool $warnedAboutUnusableRoles = false;

    public function __construct(
        private readonly Config $config,
        private readonly RoleHierarchy $roles,
        private readonly ?LoggerInterface $logger = null,
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

        // The raw list is kept, not only its normalised form: telling "nobody configured a restriction"
        // apart from "somebody configured one this class could not read" is the whole safety of the gate.
        $configured = $this->config->array('firefly.management.endpoint.health.roles', []);
        $required = $this->required($configured);

        if ($required === []) {
            if ($configured !== []) {
                $this->warnAboutUnusableRoles($configured);

                return false;
            }

            return true;
        }

        $held = $this->roles->reachableAuthorities($authentication->authorityStrings());

        return array_intersect($required, $held) !== [];
    }

    /**
     * The configured roles, normalised to the `ROLE_` spelling and de-duplicated. Entries that are not
     * usable role names — a blank string, a null left by a dangling YAML key, a number, a nested array —
     * are dropped here and COUNTED as dropped by the caller, which is where the decision lives.
     *
     * The key itself is spelled in full at the read site in mayReadDetails() rather than hidden behind a
     * constant, so tests/ConfigReferenceTest can see it and hold skeleton/config/firefly.php to documenting
     * it.
     *
     * @param  array<array-key, mixed>  $configured  the raw value of the roles key
     * @return list<string>
     */
    private function required(array $configured): array
    {
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

    /**
     * Name the key, the count and the deny. An operator staring at a health body with no components and a
     * `roles` list right there in their config file has no other thread to pull.
     *
     * @param  array<array-key, mixed>  $configured
     */
    private function warnAboutUnusableRoles(array $configured): void
    {
        if ($this->warnedAboutUnusableRoles) {
            return;
        }

        $this->warnedAboutUnusableRoles = true;

        $this->logger?->warning(sprintf(
            'firefly.management.endpoint.health.roles lists %d entr%s, none of them a usable role name; '
            .'health details are refused to every caller. An EMPTY list is how you say "any authenticated '
            .'principal" — fix the entries or remove the key.',
            count($configured),
            count($configured) === 1 ? 'y' : 'ies',
        ));
    }
}
