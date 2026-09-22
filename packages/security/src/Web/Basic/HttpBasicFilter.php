<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Basic;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Authentication\AuthenticationManager;
use Firefly\Security\Authentication\Exception\BadCredentialsException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Session\SessionSecuritySettings;
use Firefly\Security\Web\EntryPoint\BasicAuthenticationEntryPoint;
use Firefly\Security\Web\Settings\HttpBasicSettings;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;

/**
 * `Authorization: Basic` (RFC 7617; Spring's BasicAuthenticationFilter). Ordered -91: after the session
 * persistence filter and the form-login filter — a browser that already signed in and keeps sending the
 * header is not re-verified — and before the two bearer filters, so a request that carries a Basic header
 * is decided here and never reaches them. In order:
 *
 *   1. no `Basic` header, or a principal already in the holder: the request proceeds untouched (the
 *      anonymous path, where the deny-by-default HttpSecurityFilter decides access and the entry point
 *      issues the challenge),
 *   2. a header that does not parse — not base64, no `:`, an empty username — is answered with the 401
 *      challenge from the BasicAuthenticationEntryPoint at once, with no attempt against the manager and no
 *      failure event: there is no username to name,
 *   3. AuthenticationManager::authenticate() with the pair — DaoAuthenticationProvider's timing and content
 *      equalisation is inherited whole, so an unknown user and a wrong password are one and the same 401;
 *      the failure event carries the username and the source ip, never the password,
 *   4. on success: the context is established in the holder for the rest of the chain and both success
 *      events fire with the BASIC mechanism. STATELESS BY DEFAULT: nothing is stored, and the next request
 *      is verified again from its own header, which is the right shape for an API client that sends the
 *      pair every time. With `http_basic.session` on, a success is stored like a form login instead — the
 *      session id is regenerated first (fixation protection, the same rule the form-login and remember-me
 *      filters apply) and the context goes through the SecurityContextRepository — so a browser sends the
 *      header once and the persistence filter carries the principal from then on.
 *
 * The holder is cleared in `finally` like every other authentication filter: the repository save, when
 * there is one, is what carries the sign-in to the next request, and nothing may bleed into the next
 * request on the same worker.
 *
 * THE CHALLENGE ON FAILURE COMES FROM THE BASIC ENTRY POINT, NEVER THE CONFIGURED ONE. A request that
 * presented Basic credentials has chosen its mechanism; sending it to a login page because form login is
 * also on would be the wrong answer to an API client, exactly as Spring's BasicAuthenticationFilter keeps
 * its own BasicAuthenticationEntryPoint whatever the HttpSecurity's entry point is.
 *
 * THE SCHEME NAME IS MATCHED CASE-INSENSITIVELY (RFC 7235 §2.1: the scheme is a case-insensitive token),
 * and the base64 is decoded strictly: a header whose token68 carries characters outside the alphabet is
 * malformed, not "mostly right".
 *
 * AND-GATED: the master flag PLUS `http_basic.enabled`, the way HttpSecurityFilter pairs the master flag
 * with `http.enabled`. The master condition is what the other filters carry too (their beans are
 * master-gated). The surface condition is load-bearing here for a different reason: this filter takes the
 * BasicAuthenticationEntryPoint, and that entry point takes the two web renderers, which a boot that
 * registers no WebServiceProvider — a CQRS worker, a console process — has no business resolving. Under the
 * master flag alone the EagerSingletonsPass would construct this filter at every such boot and die on the
 * renderers' view factory; under both flags a boot only pays for the renderers when it has asked for the
 * mechanism that needs them. shouldNotFilter() re-reads both keys live, so a test that flips them after
 * boot is honoured all the same.
 */
#[Component]
#[Order(-91)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.http_basic.enabled', havingValue: 'true')]
final class HttpBasicFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly HttpBasicSettings $settings,
        private readonly AuthenticationManager $manager,
        private readonly BasicAuthenticationEntryPoint $entryPoint,
        private readonly SecurityContextRepository $repository,
        private readonly SessionSecuritySettings $session,
        private readonly AuthenticationEventPublisher $events,
        private readonly Config $config,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.http_basic.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        if (! self::isBasic($request->header('Authorization'))) {
            return $next($request);
        }

        if (SecurityContextHolder::getContext()->isAuthenticated()) {
            return $next($request);
        }

        $credentials = self::credentials($request);
        if ($credentials === null) {
            return $this->entryPoint->commence($request, new BadCredentialsException('Bad credentials.'));
        }
        [$username, $password] = $credentials;

        try {
            $authentication = $this->manager->authenticate(Authentication::unauthenticated($username, $username, $password));
        } catch (AuthenticationException $e) {
            $this->events->publishAuthenticationFailure($e, $username, (string) $request->ip());

            return $this->entryPoint->commence($request, $e);
        }

        try {
            $store = $this->settings->session && $request->hasSession();
            if ($store && $this->session->fixationProtection()) {
                $request->session()->migrate(true);
            }

            $context = new SecurityContext($authentication);
            SecurityContextHolder::setContext($context);
            if ($store) {
                $this->repository->save($context, $request);
            }
            $this->events->publishInteractiveSuccess($authentication, InteractiveAuthenticationSuccessEvent::BASIC);

            return $next($request);
        } finally {
            SecurityContextHolder::clearContext();
        }
    }

    /**
     * The username and password of a `Basic` header, or null when the header is not one, is not base64, or
     * has no `:` — the password may contain colons, the username may not (RFC 7617 §2) — or names an empty
     * user.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function credentials(Request $request): ?array
    {
        $header = $request->header('Authorization');
        if (! self::isBasic($header)) {
            return null;
        }

        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return $username === '' ? null : [$username, $password];
    }

    /**
     * @phpstan-assert-if-true string $header
     */
    private static function isBasic(mixed $header): bool
    {
        return is_string($header) && strncasecmp($header, 'Basic ', 6) === 0;
    }
}
