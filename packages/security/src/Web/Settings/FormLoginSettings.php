<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Settings;

use Firefly\Config\Config;
use Illuminate\Http\Request;

/**
 * Everything the form-login mechanism reads from `firefly.security.form_login.*` (Spring's formLogin()
 * customizer, as configuration). URLs are compared BY PATH: `/login?error` is the login page, and a trailing
 * slash does not make a different address.
 *
 * `pageEnabled` is whether the login PAGE exists — form login, or OAuth2 login
 * (`firefly.security.oauth2.client.login.enabled`), which lists its providers on the same page and needs the
 * same route, the same permit in HttpSecurityFilter and the same redirect from the entry point. `enabled` stays
 * "the password form and the POST": with only OAuth2 login on, the page is mounted without a form and
 * FormLoginFilter answers nothing. That one key is the only `oauth2.client.*` fact the security core reads,
 * and it reads it the way it reads its own: an OAuth2 login IS a form of interactive login, and Spring's
 * HttpSecurity knows about oauth2Login() exactly as it knows about formLogin().
 */
final readonly class FormLoginSettings
{
    public bool $pageEnabled;

    public function __construct(
        public bool $enabled = false,
        public string $loginPage = '/login',
        public string $loginProcessingUrl = '/login',
        public string $usernameParameter = 'username',
        public string $passwordParameter = 'password',
        public string $defaultSuccessUrl = '/',
        public bool $alwaysUseDefaultSuccessUrl = false,
        public string $failureUrl = '/login?error',
        public ?string $view = null,
        ?bool $pageEnabled = null,
    ) {
        $this->pageEnabled = $pageEnabled ?? $enabled;
    }

    public static function fromConfig(Config $config): self
    {
        $view = $config->string('firefly.security.form_login.view', '');
        $enabled = $config->bool('firefly.security.form_login.enabled', false);

        return new self(
            enabled: $enabled,
            loginPage: $config->string('firefly.security.form_login.login_page', '/login'),
            loginProcessingUrl: $config->string('firefly.security.form_login.login_processing_url', '/login'),
            usernameParameter: $config->string('firefly.security.form_login.username_parameter', 'username'),
            passwordParameter: $config->string('firefly.security.form_login.password_parameter', 'password'),
            defaultSuccessUrl: $config->string('firefly.security.form_login.default_success_url', '/'),
            alwaysUseDefaultSuccessUrl: $config->bool('firefly.security.form_login.always_use_default_success_url', false),
            failureUrl: $config->string('firefly.security.form_login.failure_url', '/login?error'),
            view: $view === '' ? null : $view,
            pageEnabled: $enabled || $config->bool('firefly.security.oauth2.client.login.enabled', false),
        );
    }

    public function isLoginPage(Request $request): bool
    {
        return $this->pageEnabled
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && self::path($request->getPathInfo()) === self::path($this->loginPage);
    }

    public function isLoginProcessing(Request $request): bool
    {
        return $this->enabled
            && $request->isMethod('POST')
            && self::path($request->getPathInfo()) === self::path($this->loginProcessingUrl);
    }

    /** The `/`-prefixed path part of a URL or path, without a trailing slash: `/login?error` and `/login/` are both `/login`. */
    public static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return '/'.trim(is_string($path) ? $path : '', '/');
    }
}
