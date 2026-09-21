<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Security\OAuth2\Client\Boot\OAuth2ClientWiringPass;
use Firefly\Security\OAuth2\Client\Http\OAuth2ClientHttpMacros;
use Illuminate\Contracts\Config\Repository;

/**
 * The boot-pass half of firefly/security-oauth2-client (cannot ride on SecurityOAuth2ClientServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Contributes OAuth2ClientWiringPass (order 210,
 * right after SecurityWiringPass at 200, so the security master flag's own guards have already run). It also
 * registers `Http::oauth2Client()` at register() time, behind `http.macro`. Both this and
 * SecurityOAuth2ClientServiceProvider are in extra.laravel.providers.
 */
final class SecurityOAuth2ClientWiringProvider extends FireflyServiceProvider
{
    /**
     * The Http macro is registered HERE, at register() time (see OAuth2ClientHttpMacros for why), reading the key
     * through Laravel's repository because the Config port is a bean and beans do not exist yet.
     */
    public function register(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');
        if ((bool) $config->get('firefly.security.oauth2.client.http.macro', true)) {
            OAuth2ClientHttpMacros::register($this->app);
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new OAuth2ClientWiringPass];
    }
}
