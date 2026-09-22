<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Authentication\AuthenticationManager;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Session\SavedRequest;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Session\SessionSecuritySettings;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Firefly\Security\Web\RememberMe\RememberMeServices;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST {login_processing_url} (Spring's UsernamePasswordAuthenticationFilter). It answers the request itself
 * — nothing after it in the chain, and no route, ever sees a login POST — in this order:
 *
 *   1. the session CSRF token (SessionCsrf: a login CSRF is an attack, so this does not depend on CsrfFilter),
 *   2. AuthenticationManager::authenticate() with the two form fields — DaoAuthenticationProvider's
 *      timing/content equalisation is inherited whole, and the failure event carries the username and the
 *      source ip, never the password,
 *   3. on success: the session id is regenerated (fixation protection; attributes and the saved request are
 *      kept), the context is stored through the SecurityContextRepository, the events fire, the remember-me
 *      cookie is set when the port is bound, and the browser goes to the saved request or the default URL,
 *   4. on failure: a redirect to `failure_url` (`/login?error`, which the page turns into the error state).
 *
 * The holder is cleared in `finally` on both paths: the response is complete, and nothing may bleed into the
 * next request on the same worker.
 *
 * WHY THE SESSION ID CHANGES BUT THE TOKEN DOES NOT. Store::migrate(true) issues a fresh id and deletes the
 * old file while keeping every attribute, which is exactly session fixation protection: an id an attacker
 * planted before the sign-in names nothing afterwards. The CSRF token is an attribute, so it survives — a
 * page the browser still has open keeps working — and the saved request survives with it, which is what
 * lets step 3 send the person back where they were refused.
 *
 * THE REDIRECT TARGETS GO THROUGH LARAVEL'S UrlGenerator, not Request::getUriForPath(): `to()` is what a
 * controller's redirect('/') builds and what a test's assertRedirect('/') compares against, it honours an
 * application's URL::forceScheme()/forceRootUrl() behind a TLS-terminating proxy, and it returns an absolute
 * URL — the saved request is one — untouched. A configured `default_success_url` of `/` therefore lands on
 * the same `http://host` every other redirect in the application produces, not on `http://host/`. The
 * generator is resolved from the container ON USE, never injected: Laravel builds it from the bound
 * `request`, and this filter is an eager singleton the EagerSingletonsPass constructs at every boot — a bare
 * harness, a boot that never binds a request — so a constructor dependency on it would fail boots that never
 * answer a login (the same reason SessionCsrf resolves the Encrypter on its first use).
 */
#[Component]
#[Order(-92)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
final class FormLoginFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly FormLoginSettings $settings,
        private readonly SessionSecuritySettings $session,
        private readonly AuthenticationManager $manager,
        private readonly SecurityContextRepository $repository,
        private readonly AuthenticationEventPublisher $events,
        private readonly SessionCsrf $csrf,
        private readonly Container $container,
        private readonly Config $config,
        private readonly ?RememberMeServices $rememberMe = null,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.form_login.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        if (! $this->settings->isLoginProcessing($request)) {
            return $next($request);
        }

        $this->csrf->verify($request);

        $username = $this->field($request, $this->settings->usernameParameter);
        $password = $this->field($request, $this->settings->passwordParameter);

        try {
            $authentication = $this->manager->authenticate(Authentication::unauthenticated($username, $username, $password));
        } catch (AuthenticationException $e) {
            $this->events->publishAuthenticationFailure($e, $username, (string) $request->ip());

            return $this->redirect($this->settings->failureUrl);
        }

        try {
            if ($request->hasSession() && $this->session->fixationProtection()) {
                $request->session()->migrate(true);
            }

            $context = new SecurityContext($authentication);
            SecurityContextHolder::setContext($context);
            $this->repository->save($context, $request);
            $this->events->publishInteractiveSuccess($authentication, InteractiveAuthenticationSuccessEvent::FORM);

            $response = $this->redirect($this->successUrl($request));
            $this->rememberMe?->loginSuccess($request, $response, $authentication);

            return $response;
        } finally {
            SecurityContextHolder::clearContext();
        }
    }

    /** A form field as the string it is; an array or a missing field is an empty string, never a type error. */
    private function field(Request $request, string $name): string
    {
        /** @var mixed $value */
        $value = $request->input($name, '');

        return is_scalar($value) ? (string) $value : '';
    }

    private function successUrl(Request $request): string
    {
        if (! $this->settings->alwaysUseDefaultSuccessUrl && $request->hasSession()) {
            $saved = SavedRequest::consume($request->session());
            if ($saved !== null) {
                return $saved;
            }
        }

        return $this->settings->defaultSuccessUrl;
    }

    /** A 302 to a configured path or an absolute URL, formatted exactly as the application's own redirects are. */
    private function redirect(string $url): RedirectResponse
    {
        /** @var UrlGenerator $urls */
        $urls = $this->container->make(UrlGenerator::class);

        return new RedirectResponse($urls->to($url));
    }
}
