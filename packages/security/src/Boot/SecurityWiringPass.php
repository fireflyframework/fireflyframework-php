<?php

declare(strict_types=1);

namespace Firefly\Security\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\Web\MethodSecurityControllerGuard;
use Firefly\Web\Security\ControllerSecurityGuard;

/**
 * Three boot-time actions gated for correctness, in this order:
 *
 * (0) MUTUAL-EXCLUSIVITY GUARD, unconditional (runs even when the master flag is off): local-JWT
 * (JwtAuthenticationFilter, #[Order(-90)]) and the OAuth2 resource server (OAuth2ResourceServerFilter,
 * #[Order(-85)]) are each gated ONLY by their own surface flag — NOT by firefly.security.enabled — so both can be
 * turned on independently of the master flag. If both ARE on, the −90 filter runs first on every request and
 * unconditionally rejects any Bearer token it cannot decode with the LOCAL HMAC secret (JwtService::decode()
 * throws InvalidTokenException/TokenExpiredException for anything that isn't a valid local token) BEFORE the −85
 * filter — which validates against the issuer's JWKS — ever gets a chance to run. A genuine OAuth2-issued token
 * would therefore always 401 at the local filter, never reaching the resource-server filter that could actually
 * validate it: a broken (though fail-closed — no auth bypass results) configuration. Refused at BOOT, fail-closed,
 * exactly like JwtService's own weak-secret guard below — a misconfigured app must not silently serve a filter
 * chain that can never authenticate real OAuth2 clients.
 *
 * The remaining two run only `when firefly.security.enabled` (WiringPasses, after FlushDefinitions so every
 * #[Bean] is registered):
 * (1) OVERRIDE web's no-op ControllerSecurityGuard with the real MethodSecurityControllerGuard via a container
 * instance() bind — unconditional and boot-order-independent, since the ControllerDispatcher resolves the guard
 * fresh per request; (2) when JWT auth is enabled, eagerly resolve JwtService so its weak-secret guard FAILS FAST
 * at boot (not lazily on the first request). Skips (1)/(2) entirely when disabled, leaving the secure default
 * (web's AllowAll guard) untouched.
 */
final class SecurityWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 200;
    }

    public function run(BootContext $context): void
    {
        $config = $context->config;

        if ($config->bool('firefly.security.jwt.enabled', false) && $config->bool('firefly.security.oauth2.resource_server.enabled', false)) {
            throw new ConfigurationException('Enable EITHER local JWT authentication (firefly.security.jwt.enabled) OR the OAuth2 resource server (firefly.security.oauth2.resource_server.enabled), not both: the local-JWT filter (order -90) rejects bearer tokens before the resource-server filter (order -85) can validate them.');
        }

        if (! $config->bool('firefly.security.enabled', false)) {
            return;
        }

        $container = $context->container;
        $container->instance(ControllerSecurityGuard::class, $container->make(MethodSecurityControllerGuard::class));

        if ($config->bool('firefly.security.jwt.enabled', false)) {
            $container->make(JwtService::class); // fail-fast weak-secret guard at boot
        }
    }
}
