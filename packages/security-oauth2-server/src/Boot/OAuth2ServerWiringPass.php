<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Boot;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\Session\SessionSecuritySettings;

/**
 * The server's boot-time refusals, all under `firefly.security.oauth2.server.enabled` (an application that never
 * turned the server on is never refused over it), in this order:
 *
 *  (1) THE MASTER FLAG. Every bean here consumes master-gated beans (PasswordEncoder for client secrets,
 *      SessionCsrf for the consent form, FormLoginSettings and the entry point for the sign-in redirect), so the
 *      server without `firefly.security.enabled` is not a degraded server: it is no server, with nothing to say
 *      so. Refused, naming the key.
 *  (2) SESSION SECURITY. The authorization endpoint answers a BROWSER and needs the principal the session
 *      carries (SessionSecuritySettings::enabled(): form_login, session.enabled, remember_me or
 *      http_basic.session); without it every authorization request would be anonymous forever, sent to a login
 *      page that could never come back. Refused, naming the keys.
 *  (3) THE LOCAL HMAC FILTER. JwtAuthenticationFilter (-90) rejects any bearer it cannot decode with the local
 *      secret before this package's filter (-82) runs, so with `firefly.security.jwt.enabled` on every token this
 *      server issues is refused at /userinfo before the server could examine it — the same fail-closed dead end
 *      SecurityWiringPass refuses for jwt + oauth2.resource_server. Refused.
 *  (4) THE SETTINGS, KEYS AND CLIENTS are resolved now, so a bad algorithm, a missing signing key or a client
 *      block with no redirect URI is a startup failure (each bean refuses in its own constructor), not a 500 on
 *      the first request that needs it. JwtSigningKeys is resolved right after the settings, so an empty or
 *      unloadable signing key refuses the boot with the command that generates one.
 */
final class OAuth2ServerWiringPass implements BootPass
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
        if (! $context->config->bool('firefly.security.oauth2.server.enabled', false)) {
            return;
        }

        self::assertRunnable($context->config);

        $context->container->make(AuthorizationServerSettings::class);
        $context->container->make(JwtSigningKeys::class);
    }

    /**
     * Refusals (1)–(3), as one static so the rules are testable against a bare Config without a boot; a no-op
     * while the server is off.
     */
    public static function assertRunnable(Config $config): void
    {
        if (! $config->bool('firefly.security.oauth2.server.enabled', false)) {
            return;
        }

        if (! $config->bool('firefly.security.enabled', false)) {
            throw new ConfigurationException(
                'firefly.security.oauth2.server.enabled is on but firefly.security.enabled is off: the authorization server '
                .'needs the password encoder, the session CSRF check and the login redirect the master flag gates. Turn firefly.security.enabled on.'
            );
        }

        if (! (new SessionSecuritySettings($config))->enabled()) {
            throw new ConfigurationException(
                'firefly.security.oauth2.server.enabled is on but nothing carries a principal between requests: the authorization '
                .'endpoint needs a session-held user. Turn on firefly.security.form_login.enabled (the framework login page), or '
                .'firefly.security.session.enabled with a sign-in mechanism of your own.'
            );
        }

        if ($config->bool('firefly.security.jwt.enabled', false)) {
            throw new ConfigurationException(
                'firefly.security.oauth2.server.enabled and firefly.security.jwt.enabled are both on: the local HMAC filter (-90) '
                .'rejects every RS256/ES256 token this server issues before the server could examine it. Turn jwt.enabled off; '
                .'verify the server\'s own tokens with firefly.security.oauth2.resource_server (jwks_source: local).'
            );
        }
    }
}
