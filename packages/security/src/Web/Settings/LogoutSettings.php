<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Settings;

use Firefly\Config\Config;
use Illuminate\Http\Request;

/**
 * `firefly.security.logout.*`. `enabled` follows `form_login.enabled` or `oauth2.client.login.enabled` when
 * unset — an interactive login without a way out is not a feature — and the logout URL accepts POST only,
 * because a GET that signs someone out is a link an attacker can make a victim click.
 */
final readonly class LogoutSettings
{
    /**
     * @param  list<string>  $deleteCookies
     */
    public function __construct(
        public bool $enabled = false,
        public string $logoutUrl = '/logout',
        public string $logoutSuccessUrl = '/login?logout',
        public bool $invalidateSession = true,
        public array $deleteCookies = [],
        public bool $clearAuthentication = true,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $cookies = [];
        foreach ($config->array('firefly.security.logout.delete_cookies', []) as $cookie) {
            if (is_string($cookie) && $cookie !== '') {
                $cookies[] = $cookie;
            }
        }

        return new self(
            enabled: $config->bool('firefly.security.logout.enabled', $config->bool('firefly.security.form_login.enabled', false) || $config->bool('firefly.security.oauth2.client.login.enabled', false)),
            logoutUrl: $config->string('firefly.security.logout.logout_url', '/logout'),
            logoutSuccessUrl: $config->string('firefly.security.logout.logout_success_url', '/login?logout'),
            invalidateSession: $config->bool('firefly.security.logout.invalidate_session', true),
            deleteCookies: $cookies,
            clearAuthentication: $config->bool('firefly.security.logout.clear_authentication', true),
        );
    }

    public function isLogout(Request $request): bool
    {
        return $this->enabled
            && $request->isMethod('POST')
            && FormLoginSettings::path($request->getPathInfo()) === FormLoginSettings::path($this->logoutUrl);
    }
}
