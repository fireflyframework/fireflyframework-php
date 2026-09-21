<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

/**
 * Everything the login page needs, and what a `firefly.security.form_login.view` receives as `$login`. Built
 * by LoginPageAction from the settings and the request; the page itself is a pure function of it, so a Blade
 * override and the framework page are handed exactly the same facts and neither reads configuration or the
 * request on its own.
 *
 * `form` says whether the username/password form is drawn (false when only OAuth2 login is on); `links` is
 * what a LoginPageLinks port answered, drawn as "Sign in with …" buttons after the form.
 */
final readonly class LoginPageModel
{
    public function __construct(
        public string $title,
        public string $action,
        public string $usernameParameter,
        public string $passwordParameter,
        public string $csrfToken,
        public bool $error,
        public bool $loggedOut,
        public ?string $rememberMeParameter,
        public bool $form = true,
        /** @var list<LoginPageLink> */
        public array $links = [],
    ) {}
}
