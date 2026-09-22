<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET {login_page}. Builds the LoginPageModel from the settings and the request (`?error` after a refused
 * login, `?logout` after a sign-out — the two query flags the filters redirect with) and renders either the
 * configured Blade view or the framework page. An override that throws, or that names no view on disk, falls
 * back to the built-in page for the same reason the error page does — a person who cannot sign in cannot fix
 * the view — AND THE FALLBACK IS LOGGED at warning, naming the view: the framework page answering a 200 is
 * indistinguishable from an unconfigured application, so without the line a typo in `view` would replace the
 * application's branded page with nothing to say so, and no test of the application would notice.
 *
 * THE FORM'S ACTION IS ROOT-RELATIVE — the request's base URL (the front-controller prefix Symfony worked out,
 * `/index.php` or `/app/public` when the application is not served from a rewritten document root) followed
 * by the PATH of `login_processing_url` — exactly what Spring's DefaultLoginPageGeneratingFilter writes
 * (`contextPath + loginProcessingUrl`). It is deliberately not Request::getUriForPath(): that is absolute, and
 * its scheme is the one PHP saw. Behind a TLS-terminating proxy the skeleton does not trust, PHP sees http, so
 * the page the browser fetched over https would carry `action="http://host/login"`; the browser would post
 * to http, the proxy would answer its usual 301 to https, and a 301 turns the POST into a GET /login — the
 * sign-in silently never happens. URL::forceScheme('https'), the usual Laravel remedy, cannot reach a URL the
 * request object builds. A root-relative action is same-origin with the page BY CONSTRUCTION — scheme, host
 * and port are whatever the browser used for the page — and keeps the base path, so it lands where
 * FormLoginFilter listens (the filter compares the path only, so the query part of a configured processing
 * URL is not part of the address either).
 *
 * The token is the session's own: the filter verifies it through SessionCsrf, which is why a page rendered
 * without a session (a bare boot, a test that skipped the session middleware) carries an empty token and the
 * POST it produces is refused.
 *
 * The links come from the `LoginPageLinks` port when one is bound (firefly/security-oauth2-client's
 * registrations); with form login off and only links on, the page is drawn without the form.
 */
final class LoginPageAction
{
    public function __construct(
        private readonly FormLoginSettings $settings,
        private readonly RememberMeSettings $rememberMe,
        private readonly ErrorPageSettings $pages,
        private readonly ?ViewFactory $views = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?LoginPageLinks $links = null,
    ) {}

    public function __invoke(Request $request): Response
    {
        $login = new LoginPageModel(
            title: $this->pages->title,
            action: $request->getBaseUrl().FormLoginSettings::path($this->settings->loginProcessingUrl),
            usernameParameter: $this->settings->usernameParameter,
            passwordParameter: $this->settings->passwordParameter,
            csrfToken: $request->hasSession() ? $request->session()->token() : '',
            error: $request->query->has('error'),
            loggedOut: $request->query->has('logout'),
            rememberMeParameter: $this->rememberMe->enabled ? $this->rememberMe->parameter : null,
            form: $this->settings->enabled,
            links: $this->links?->links($request) ?? [],
        );

        return new Response($this->body($login), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The application's view when `firefly.security.form_login.view` names one that exists and renders, and
     * the framework page otherwise — never silently: every path on which a configured view is NOT what the
     * browser gets is a warning naming that view, and the default configuration (no view) logs nothing.
     */
    private function body(LoginPageModel $login): string
    {
        $view = $this->settings->view;
        if ($view === null) {
            return LoginPage::render($login);
        }

        if ($this->views === null) {
            $this->logger?->warning("The login view [{$view}] cannot be rendered because no view factory is bound; the framework page was served instead.", ['view' => $view]);

            return LoginPage::render($login);
        }

        try {
            if ($this->views->exists($view)) {
                return $this->views->make($view, ['login' => $login])->render();
            }

            $this->logger?->warning("The login view [{$view}] does not exist; the framework page was served instead.", ['view' => $view]);
        } catch (Throwable $e) {
            $this->logger?->warning("The login view [{$view}] failed to render; the framework page was served instead: {$e->getMessage()}", ['view' => $view, 'exception' => $e]);
        }

        return LoginPage::render($login);
    }
}
