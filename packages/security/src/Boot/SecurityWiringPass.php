<?php

declare(strict_types=1);

namespace Firefly\Security\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Scan\AppScan;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\User\UserDetailsService;
use Firefly\Security\Web\Argument\SecurityArgumentResolver;
use Firefly\Security\Web\EntryPoint\DelegatingAuthenticationEntryPoint;
use Firefly\Security\Web\MethodSecurityControllerGuard;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolvers;
use Firefly\Web\Security\ControllerSecurityGuard;

/**
 * Eight boot-time actions gated for correctness, in this order:
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
 * exactly like JwtService's own weak-secret guard right below it.
 *
 * (1) WEAK-SECRET GUARD, ALSO unconditional (runs even when the master flag is off): JwtAuthenticationFilter is
 * gated ONLY by firefly.security.jwt.enabled, not by firefly.security.enabled (see SecurityAutoConfiguration), so a
 * weak/placeholder JWT secret must be caught at BOOT whenever jwt.enabled — not lazily on the first request — even
 * if the master flag never gets flipped on. Eagerly resolving JwtService here (its #[Bean] depends only on Config
 * scalars, never on anything master-gated) forces its constructor's weak-secret guard to run now, so
 * WeakSigningSecretException surfaces at boot instead of as a 500 on first use.
 *
 * (2) WEAK REMEMBER-ME KEY GUARD, unconditional like (1): RememberMeSettings::fromConfig() runs the JwtService
 * secret rule on firefly.security.remember_me.key whenever remember_me.enabled — the key signs every long-lived
 * cookie, so a placeholder is refused at boot rather than on the first sign-in.
 *
 * (3) PRINCIPAL INJECTION, unconditional as well: registers the SecurityArgumentResolver into web's
 * HandlerMethodArgumentResolvers, so a controller action's `Authentication`, `SecurityContext`, `UserDetails`,
 * `#[AuthenticationPrincipal]` and `#[CurrentSecurityContext]` parameters are bound from the holder before
 * ArgumentResolver would fall back to its own kinds. It runs with the master flag OFF on purpose: a parameter the
 * controller declared as the signed-in principal must never degrade to the fallback its type alone would plan —
 * a `mixed`/scalar `#[AuthenticationPrincipal]` is a REQUIRED QUERY PARAMETER to firefly/web, so without the
 * resolver `?principal=admin` would be bound straight into the action while the feature is off (fail-open, hidden
 * behind a 400 for the honest caller), and a class-typed `Authentication $auth` a 500 from the container. With
 * the resolver always present, the annotations answer what the HOLDER holds, never the flag: with nothing
 * authenticating they are genuinely inert — `#[AuthenticationPrincipal]` and `?UserDetails` are null,
 * `#[CurrentSecurityContext]` is the anonymous context, and a non-nullable `Authentication` is an honest 401 —
 * and a principal a master-independent bearer filter established (jwt, oauth2.resource_server; see (1)) reaches
 * them exactly as it would with the master flag on. The registry is a singleton WebServiceProvider bound before
 * RouteWiringPass built the dispatcher, so add()ing into it here — after — is seen by the ArgumentResolver
 * already constructed; it is joined, never replaced, so a resolver the application registered itself keeps its
 * place ahead of this one. Skipped only when web is not installed at all.
 *
 * The remaining actions run only `when firefly.security.enabled` (WiringPasses, after FlushDefinitions so every
 * #[Bean] is registered):
 *
 * (4) USER STORE GUARD: the UserDetailsService bean is resolved, so UserStoreSettings::fromConfig() has run by
 * the time boot completes — an unknown `firefly.security.users.driver`, an `eloquent` driver naming no model, or
 * a model class that does not exist is a ConfigurationException at boot, never a 500 on the first login attempt.
 * For the shipped store this is a cached lookup: the EagerSingletonsPass (900) constructs every non-#[Lazy]
 * #[Bean] before this phase, and the AuthenticationManager bean takes the store as a constructor argument, so
 * it is built at boot whether or not anything here asks. The explicit resolution is the guarantee stated in one
 * place — the same belt the JwtService guard in (1) wears — and it survives an application that binds its own
 * store #[Lazy]. Cheap by construction: neither driver touches the database to build (the memory store reads its
 * map, the Eloquent one holds its settings until a lookup).
 *
 * (5) ENTRY-POINT MODE GUARD: `firefly.security.http.entry_point` is validated — an unknown value, or `login`
 * without form login, is a ConfigurationException. The authenticationEntryPoint #[Bean] validates it too, but
 * that bean exists only while the HTTP surface is on; this runs whenever the master flag is, so a meaningless
 * mode is refused at boot rather than left for the day http.enabled is flipped.
 *
 * (6) STALE-CACHE GUARD, under the master flag and `firefly.security.method.enabled`: a compiled
 * security-methods.php with no proxy-plan.php beside it was written by a firefly:cache from before the proxy plan
 * existed. Such a cache lists every rule in the manifest while DataAutoConfiguration::proxyPlan() bridges a
 * transactional-only plan from transactional.php, so a #[Service] whose rules are method security alone is
 * handed out bare and one that also carries #[Transactional] gets a proxy with no security link — the rows
 * compile, nothing enforces them, nothing logs it, and `method.strict` cannot see it because the manifest it
 * checks for IS present. Refused at boot, fail-closed, with the remedy named: recompile. Reflection-free — two
 * file probes — and quiet while `firefly:cache` itself is the running command (AppScan::cachedFile() answers
 * null while regenerating), which is what writes the missing file. Skipped when `method.enabled` is off,
 * because the proxy link is then a pass-through by the operator's own choice and the stale plan changes nothing.
 *
 * (7) OVERRIDE web's no-op ControllerSecurityGuard with the real MethodSecurityControllerGuard via a container
 * instance() bind — unconditional and boot-order-independent, since the ControllerDispatcher resolves the guard
 * fresh per request. Skipped entirely when disabled, leaving the secure default (web's AllowAll guard) untouched.
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

        if ($config->bool('firefly.security.jwt.enabled', false)) {
            $context->container->make(JwtService::class); // fail-fast weak-secret guard at boot, independent of the master flag
        }

        if ($config->bool('firefly.security.remember_me.enabled', false)) {
            // The same fail-fast the JWT secret gets, independent of the master flag: a weak remember-me key
            // signs every long-lived cookie, and a boot is the moment to refuse it.
            RememberMeSettings::fromConfig($config);
        }

        // Principal injection, independent of the master flag: the resolver claims Authentication/UserDetails/
        // #[AuthenticationPrincipal]/#[CurrentSecurityContext] parameters before ArgumentResolver would fall back
        // to a query parameter or the container. It answers what the holder holds — anonymous, so inert, unless
        // a master-independent bearer filter has signed the request in.
        if ($context->container->bound(HandlerMethodArgumentResolvers::class)) {
            $context->container->make(HandlerMethodArgumentResolvers::class)->add(new SecurityArgumentResolver);
        }

        if (! $config->bool('firefly.security.enabled', false)) {
            return;
        }

        $container = $context->container;

        // The user store's refusals (an unknown driver, a model class that does not exist) belong at boot,
        // not on the first login attempt. Resolving the bean here is enough — and cheap: neither driver
        // touches the database to construct, and the eager-singletons pass has usually built it already.
        $container->make(UserDetailsService::class);

        // Validated here as well as in the bean, so a meaningless mode is refused even when http is off and
        // the bean is never built: an unknown value, or `login` without form login (a redirect to a page that
        // does not exist is a loop, not a policy).
        DelegatingAuthenticationEntryPoint::modeFrom($config);

        if ($config->bool('firefly.security.method.enabled', true)
            && ($methods = AppScan::cachedFile($container, AppScan::SECURITY_METHODS)) !== null
            && AppScan::cachedFile($container, AppScan::PROXY_PLAN) === null) {
            throw new ConfigurationException(
                "Refusing to boot: the compiled method-security manifest at {$methods} has no proxy plan beside it ("
                .AppScan::dir($container).'/'.AppScan::PROXY_PLAN.'). The cache was written before firefly:cache compiled '
                .'the proxy plan, so a #[PreAuthorize]/#[PostAuthorize]/#[PreFilter]/#[PostFilter] on a #[Service] or any '
                .'other stereotyped bean would be compiled and never enforced. Run `php artisan firefly:cache` to recompile '
                .'every artefact.'
            );
        }

        $container->instance(ControllerSecurityGuard::class, $container->make(MethodSecurityControllerGuard::class));
    }
}
