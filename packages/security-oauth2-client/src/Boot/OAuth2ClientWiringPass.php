<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;

/**
 * Boot-time consistency for `firefly.security.oauth2.client.*`, refused rather than silently ignored:
 *
 * (1) `login.enabled` without the package master `enabled`: the login filters are triple-gated and would simply
 *     be absent — a person who turned the login on and reads a 404 at /oauth2/authorization/google has no way
 *     to know which of two flags is missing, so the boot says so.
 * (2) `login.enabled` without `firefly.security.enabled`: the login filter takes the SecurityContextRepository,
 *     the AuthenticationEventPublisher and the SessionSecuritySettings, all master-gated beans (the rule every
 *     interactive mechanism of firefly/security follows). Refused at boot for the same reason as (1).
 * (3) `logout.oidc_initiated` without `login.enabled`: there is no OIDC session to end.
 * (4) REGISTRATION GUARD, under the package master: the ClientRegistrationRepository bean is resolved, so every
 *     static refusal of OAuth2ClientPropertiesMapper::validate() — a missing client_id, a preset without its
 *     issuer, a provider with no endpoints — is a startup failure naming the key; and a login with no
 *     registration at all is refused, since the page would list nothing and the entry point would send people
 *     to it.
 * (5) EAGER DISCOVERY, when `discovery.eager`: every registration is resolved, which fetches every issuer's
 *     discovery document; an unreachable issuer is then a boot failure (ProviderDiscoveryException), which is
 *     what Spring Boot does at startup and what a deployment that would rather not find out on the first login
 *     wants.
 *
 * Runs at WiringPasses order 210 — after SecurityWiringPass (200) has made its own refusals, so a boot with two
 * problems reports the security core's first. (1)–(3) are unconditional and read Config scalars only; (4) and
 * (5) run under the package master, which is the gate the two beans they resolve are behind.
 */
final class OAuth2ClientWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 210;
    }

    public function run(BootContext $context): void
    {
        $config = $context->config;
        $client = $config->bool('firefly.security.oauth2.client.enabled', false);
        $login = $config->bool('firefly.security.oauth2.client.login.enabled', false);
        $oidcLogout = $config->bool('firefly.security.oauth2.client.logout.oidc_initiated', false);

        if ($login && ! $client) {
            throw new ConfigurationException('firefly.security.oauth2.client.login.enabled is on but firefly.security.oauth2.client.enabled is off: the login filters need the registrations, the discovery and the token client the package master switches on. Enable both, or neither.');
        }

        if ($login && ! $config->bool('firefly.security.enabled', false)) {
            throw new ConfigurationException('firefly.security.oauth2.client.login.enabled is on but firefly.security.enabled (the master flag) is off: OAuth2 login signs a principal into the session-persisted SecurityContext and publishes the authentication events, both of which exist only under the master flag.');
        }

        if ($oidcLogout && ! $login) {
            throw new ConfigurationException('firefly.security.oauth2.client.logout.oidc_initiated is on but firefly.security.oauth2.client.login.enabled is off: RP-initiated logout ends a session that only OAuth2 login can have started.');
        }

        if (! $client) {
            return;
        }

        // (4) The registrations' static refusals belong at boot: resolving the bean runs OAuth2ClientPropertiesMapper::validate().
        $container = $context->container;
        /** @var ClientRegistrationRepository $registrations */
        $registrations = $container->make(ClientRegistrationRepository::class);

        if ($login && $registrations->registrationIds() === []) {
            throw new ConfigurationException('firefly.security.oauth2.client.login.enabled is on but there is no client registration under firefly.security.oauth2.client.registration: there is nothing to sign in through.');
        }

        // (5) `discovery.eager`: Spring Boot's behaviour — every issuer is discovered now, and a dead one fails the boot.
        /** @var OAuth2ClientSettings $settings */
        $settings = $container->make(OAuth2ClientSettings::class);
        if ($settings->discoveryEager) {
            $registrations->all();
        }
    }
}
