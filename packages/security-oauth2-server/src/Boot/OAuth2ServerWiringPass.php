<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Boot;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Token\TokenEndpointRateLimiter;
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
 *      carries (SessionSecuritySettings::enabled(): form_login, session.enabled or remember_me — the fourth
 *      source it knows, http_basic.session, needs http_basic.enabled, which refusal (4) rules out); without it
 *      every authorization request would be anonymous forever, sent to a login page that could never come
 *      back. Refused, naming the keys.
 *  (3) THE LOCAL HMAC FILTER. JwtAuthenticationFilter (-90) rejects any bearer it cannot decode with the local
 *      secret before this package's filter (-82) runs, so with `firefly.security.jwt.enabled` on every token this
 *      server issues is refused at /userinfo before the server could examine it — the same fail-closed dead end
 *      SecurityWiringPass refuses for jwt + oauth2.resource_server. Refused.
 *  (4) THE HTTP BASIC FILTER. HttpBasicFilter (-91) decides every request that carries `Authorization: Basic`:
 *      it tries the pair as a USER through the AuthenticationManager and, when that fails, answers the Basic
 *      entry point's 401 and publishes a failure event — it never calls the next filter. A client's
 *      `client_secret_basic` credential (RFC 6749 §2.3.1, the default method of every confidential client) is
 *      exactly such a pair, so with `firefly.security.http_basic.enabled` on every Basic request to the token,
 *      introspection or revocation endpoint would be refused as a bad login for username = client id before
 *      this package's filter (-82) could read it, and the user lockout counters would fill with client ids.
 *      The same dead end as (3), one filter earlier. Refused, naming both keys: the server authenticates its
 *      clients itself. (Spring Authorization Server escapes this by owning a SecurityFilterChain of its own for
 *      the server's endpoints; LaraFly has one chain, so the two cannot share it.)
 *  (5) THE SETTINGS, KEYS AND CLIENTS are resolved now, so a bad algorithm, a missing signing key or a client
 *      block with no redirect URI is a startup failure (each bean refuses in its own constructor), not a 500 on
 *      the first request that needs it. JwtSigningKeys is resolved right after the settings, so an empty or
 *      unloadable signing key refuses the boot with the command that generates one.
 *  (6) THE RATE LIMITER, resolved when `rate_limit.enabled` so a missing firefly/resilience store refuses at
 *      boot (the bean names the store and the key), not on the first token request — which would otherwise be a
 *      500 for every client until someone read the log.
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
        $config = $context->config;
        if (! $config->bool('firefly.security.oauth2.server.enabled', false)) {
            return;
        }

        self::assertRunnable($config);

        $context->container->make(AuthorizationServerSettings::class);
        $context->container->make(JwtSigningKeys::class);
        $context->container->make(RegisteredClientRepository::class);
        if ($config->bool('firefly.security.oauth2.server.rate_limit.enabled', false)) {
            $context->container->make(TokenEndpointRateLimiter::class);
        }
    }

    /**
     * Refusals (1)–(4), as one static so the rules are testable against a bare Config without a boot; a no-op
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

        if ($config->bool('firefly.security.http_basic.enabled', false)) {
            throw new ConfigurationException(
                'firefly.security.oauth2.server.enabled and firefly.security.http_basic.enabled are both on: HttpBasicFilter (-91) '
                .'answers every Authorization: Basic header as a user login — a 401 and a failure event — before the server\'s '
                .'filter (-82) could read a client_secret_basic credential at the token, introspection or revocation endpoint. '
                .'Turn http_basic.enabled off; the server authenticates its clients itself.'
            );
        }
    }
}
