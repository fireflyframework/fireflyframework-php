<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * GET {login_page}. Builds the LoginPageModel from the settings and the request (`?error` after a refused
 * login, `?logout` after a sign-out — the two query flags the filters redirect with) and renders either the
 * configured Blade view or the framework page. An override that throws falls back to the built-in page for the
 * same reason the error page does: a person who cannot sign in cannot fix the view.
 *
 * The form posts to the ABSOLUTE processing URL (Request::getUriForPath), so a page served under a base path
 * or behind a proxy submits where FormLoginFilter listens, and the token is the session's own: the filter
 * verifies it through SessionCsrf, which is why a page rendered without a session (a bare boot, a test that
 * skipped the session middleware) carries an empty token and the POST it produces is refused.
 */
final class LoginPageAction
{
    public function __construct(
        private readonly FormLoginSettings $settings,
        private readonly RememberMeSettings $rememberMe,
        private readonly ErrorPageSettings $pages,
        private readonly ?ViewFactory $views = null,
    ) {}

    public function __invoke(Request $request): Response
    {
        $login = new LoginPageModel(
            title: $this->pages->title,
            action: $request->getUriForPath($this->settings->loginProcessingUrl),
            usernameParameter: $this->settings->usernameParameter,
            passwordParameter: $this->settings->passwordParameter,
            csrfToken: $request->hasSession() ? $request->session()->token() : '',
            error: $request->query->has('error'),
            loggedOut: $request->query->has('logout'),
            rememberMeParameter: $this->rememberMe->enabled ? $this->rememberMe->parameter : null,
        );

        return new Response($this->body($login), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function body(LoginPageModel $login): string
    {
        $view = $this->settings->view;
        if ($view !== null && $this->views !== null) {
            try {
                if ($this->views->exists($view)) {
                    return $this->views->make($view, ['login' => $login])->render();
                }
            } catch (Throwable) {
                // Fall through to the built-in page.
            }
        }

        return LoginPage::render($login);
    }
}
