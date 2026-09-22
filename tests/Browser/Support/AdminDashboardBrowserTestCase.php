<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;

/**
 * The dashboard suite's application: the skeleton of BrowserTestCase plus a booted OAuth2 authorization
 * server, because the dashboard's OAuth2 page renders the `oauth2clients` actuator endpoint and that
 * endpoint is AND-gated on `firefly.security.enabled` and `firefly.security.oauth2.server.enabled` — a
 * process with the gates off registers no endpoint, so the page would answer with the "unavailable" view
 * and the suite's own `assertDontSee('This page has no endpoint to read')` would catch it.
 *
 * Only the master flag is turned on: the URL rules, CSRF, the security headers and form login stay OFF, so
 * every other scenario in the suite (the theme toggle, the dark-mode and phone-width overviews, the pages
 * that POST) drives exactly the application it drove before. Leaving
 * `firefly.security.headers.enabled` at its default matters in particular — its `default-src 'self'` policy
 * would refuse the dashboard's own inline stylesheet and script, which is a browser failure that has
 * nothing to do with what this suite is checking.
 *
 * Two clients so the page has something to show and the screenshot is worth looking at: a confidential web
 * application with the authorization-code and refresh grants, and a machine client with client credentials.
 */
abstract class AdminDashboardBrowserTestCase extends BrowserTestCase
{
    private static ?string $signingKey = null;

    /** One RSA key per process: generating a 2048-bit key per test class would cost seconds for nothing. */
    public static function signingKey(): string
    {
        return self::$signingKey ??= KeyPairGenerator::generate('RS256');
    }

    /** @return array<string, mixed> */
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.enabled' => true,
            'firefly.security.http.enabled' => false,
            'firefly.security.csrf.enabled' => false,
            'firefly.security.headers.enabled' => false,
            'firefly.security.form_login.enabled' => false,
            // The server refuses to boot with nothing to carry a principal between requests
            // (OAuth2ServerWiringPass refusal 2). The persistence filter alone satisfies it, and it changes
            // nothing for a dashboard nobody signs in to.
            'firefly.security.session.enabled' => true,
            'firefly.security.oauth2.server.enabled' => true,
            'firefly.security.oauth2.server.jwt.signing_key' => self::signingKey(),
            'firefly.security.oauth2.server.clients' => [
                'storefront' => [
                    'client_secret' => '{noop}storefront-secret',
                    'client_name' => 'Storefront',
                    'authorization_grant_types' => ['authorization_code', 'refresh_token'],
                    'redirect_uris' => ['https://storefront.test/callback'],
                    'scopes' => ['openid', 'profile', 'orders:read'],
                ],
                'reporting' => [
                    'client_secret' => '{noop}reporting-secret',
                    'client_name' => 'Nightly reporting job',
                    'authorization_grant_types' => ['client_credentials'],
                    'scopes' => ['orders:read'],
                ],
            ],
        ];
    }
}
