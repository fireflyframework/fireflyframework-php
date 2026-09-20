<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Settings;

use Firefly\Config\Config;
use Illuminate\Http\Request;

/**
 * Everything the form-login mechanism reads from `firefly.security.form_login.*` (Spring's formLogin()
 * customizer, as configuration). URLs are compared BY PATH: `/login?error` is the login page, and a trailing
 * slash does not make a different address.
 */
final readonly class FormLoginSettings
{
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
    ) {}

    public static function fromConfig(Config $config): self
    {
        $view = $config->string('firefly.security.form_login.view', '');

        return new self(
            enabled: $config->bool('firefly.security.form_login.enabled', false),
            loginPage: $config->string('firefly.security.form_login.login_page', '/login'),
            loginProcessingUrl: $config->string('firefly.security.form_login.login_processing_url', '/login'),
            usernameParameter: $config->string('firefly.security.form_login.username_parameter', 'username'),
            passwordParameter: $config->string('firefly.security.form_login.password_parameter', 'password'),
            defaultSuccessUrl: $config->string('firefly.security.form_login.default_success_url', '/'),
            alwaysUseDefaultSuccessUrl: $config->bool('firefly.security.form_login.always_use_default_success_url', false),
            failureUrl: $config->string('firefly.security.form_login.failure_url', '/login?error'),
            view: $view === '' ? null : $view,
        );
    }

    public function isLoginPage(Request $request): bool
    {
        return $this->enabled
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
