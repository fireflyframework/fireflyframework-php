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
 * A LIST AND A CSV STRING ARE THE SAME RESTRICTION. `roles: ['ADMIN', 'ACTUATOR']` and
 * `roles: 'ADMIN,ACTUATOR'` grant exactly the same thing, because Spring's property is spelled
 * `management.endpoint.health.roles=ACTUATOR,ADMIN` and because both of this key's neighbours in the same
 * config block — the web exposure include/exclude lists and each probe group's `include` — are CSV strings.
 * The alternative was a type mismatch that THREW on a read no fail-safe wraps; see configured() for why an
 * unreadable value refuses rather than 500s, which is the one failure mode /actuator/health cannot have.
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
        $configured = $this->configured();
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
     * The raw `roles` value as a LIST, whichever of its spellings the operator wrote.
     *
     * READ THROUGH `get()`, NOT `Config::array()`, AND THAT IS THE WHOLE POINT. The typed accessor THROWS a
     * ConfigurationException on a type mismatch, and this read happens on every `when-authorized` scrape of
     * /actuator/health from a call site OUTSIDE HealthEndpoint::readFailSafe() — so the throw escapes
     * handle() and ActuatorDispatchAction's outer catch renders it as a 500 problem document. A liveness
     * probe reads 500 as DOWN. `roles: ACTUATOR` instead of `roles: [ACTUATOR]` is a one-bracket typo on a
     * brand-new key, and it would have taken down the endpoint whose every other read is deliberately
     * fail-safe. An unusable value must REFUSE, which is a decision this class already knows how to make and
     * to explain; it must never answer 500, which is not a decision at all.
     *
     * A CSV STRING IS A SPELLING, NOT A MISTAKE. Spring's own property is
     * `management.endpoint.health.roles=ACTUATOR,ADMIN`, and both sibling list keys in this very config block
     * — `firefly.management.endpoints.web.exposure.include` and
     * `firefly.management.endpoint.health.group.{name}.include` — are CSV strings read with `explode(',')`.
     * An operator who writes the spelling every neighbour uses gets the meaning every neighbour gives it,
     * not a refusal and not an outage. Entry trimming is left to required(), which already does it.
     *
     * Anything else — a bool, a number, an object — becomes ONE unusable entry rather than an empty list, so
     * it falls into the warn-and-refuse path below instead of quietly widening the surface to every
     * authenticated principal. Only a genuinely ABSENT key is the empty list: `null` is how Laravel's
     * repository spells a dangling `'roles' =>` with nothing after it, Config::required() has always mapped
     * that to the default, and nothing was written there to narrow anything.
     *
     * The key is spelled in full here, not hidden behind a constant, so tests/ConfigReferenceTest can see it
     * and hold skeleton/config/firefly.php to documenting it — `get(` is in that test's discovery
     * alternation exactly as `array(` was.
     *
     * @return array<array-key, mixed>
     */
    private function configured(): array
    {
        $raw = $this->config->get('firefly.management.endpoint.health.roles', []);

        return match (true) {
            $raw === null => [],
            is_array($raw) => $raw,
            is_string($raw) => explode(',', $raw),
            default => [$raw],
        };
    }

    /**
     * The configured roles, normalised to the `ROLE_` spelling and de-duplicated. Entries that are not
     * usable role names — a blank string, a null left by a dangling YAML key, a number, a nested array —
     * are dropped here and COUNTED as dropped by the caller, which is where the decision lives.
     *
     * @param  array<array-key, mixed>  $configured  the raw value of the roles key, as configured() listed it
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
