<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client;

use Firefly\Config\Config;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Illuminate\Http\Request;

/**
 * Everything the package reads from `firefly.security.oauth2.client.*` except the registrations and the
 * providers (those are OAuth2ClientProperties). Built once as a bean; the two filters re-read their GATES
 * live through the Config port, exactly as every security filter does, so a test's withoutSecurity() or a
 * flag flipped after boot is honoured.
 *
 * The two paths are Spring Security's defaults — `/oauth2/authorization/{registrationId}` starts a login,
 * `/login/oauth2/code/{registrationId}` is where the provider sends the browser back — compared BY PATH the
 * way FormLoginSettings compares its URLs, and the id is the ONE segment after the base: a registration id
 * is a config key, so it is a single `[A-Za-z0-9._-]` token, and anything else (a second segment, `..`, an
 * empty id) is simply not this filter's request.
 */
final readonly class OAuth2ClientSettings
{
    public function __construct(
        public bool $enabled = false,
        public bool $loginEnabled = false,
        public string $authorizationEndpointBaseUri = '/oauth2/authorization',
        public string $redirectionEndpointBaseUri = '/login/oauth2/code',
        public string $defaultSuccessUrl = '/',
        public bool $alwaysUseDefaultSuccessUrl = false,
        public string $failureUrl = '/login?error',
        public int $clockSkewSeconds = 60,
        public int $connectTimeoutSeconds = 5,
        public int $timeoutSeconds = 5,
        public int $discoveryCacheTtlSeconds = 3600,
        public bool $discoveryEager = false,
        public int $jwkSetCacheTtlSeconds = 3600,
        public int $authorizedClientCacheTtlSeconds = 86400,
        public bool $httpMacro = true,
        public bool $oidcLogout = false,
        public string $postLogoutRedirectUri = '{baseUrl}/login?logout',
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            enabled: $config->bool('firefly.security.oauth2.client.enabled', false),
            loginEnabled: $config->bool('firefly.security.oauth2.client.login.enabled', false),
            authorizationEndpointBaseUri: $config->string('firefly.security.oauth2.client.login.authorization_endpoint_base_uri', '/oauth2/authorization'),
            redirectionEndpointBaseUri: $config->string('firefly.security.oauth2.client.login.redirection_endpoint_base_uri', '/login/oauth2/code'),
            defaultSuccessUrl: $config->string('firefly.security.oauth2.client.login.default_success_url', '/'),
            alwaysUseDefaultSuccessUrl: $config->bool('firefly.security.oauth2.client.login.always_use_default_success_url', false),
            failureUrl: $config->string('firefly.security.oauth2.client.login.failure_url', '/login?error'),
            clockSkewSeconds: $config->int('firefly.security.oauth2.client.clock_skew', 60),
            connectTimeoutSeconds: $config->int('firefly.security.oauth2.client.http.connect_timeout', 5),
            timeoutSeconds: $config->int('firefly.security.oauth2.client.http.timeout', 5),
            discoveryCacheTtlSeconds: $config->int('firefly.security.oauth2.client.discovery.cache_ttl', 3600),
            discoveryEager: $config->bool('firefly.security.oauth2.client.discovery.eager', false),
            jwkSetCacheTtlSeconds: $config->int('firefly.security.oauth2.client.jwk_set.cache_ttl', 3600),
            authorizedClientCacheTtlSeconds: $config->int('firefly.security.oauth2.client.authorized_client.cache_ttl', 86400),
            httpMacro: $config->bool('firefly.security.oauth2.client.http.macro', true),
            oidcLogout: $config->bool('firefly.security.oauth2.client.logout.oidc_initiated', false),
            postLogoutRedirectUri: $config->string('firefly.security.oauth2.client.logout.post_logout_redirect_uri', '{baseUrl}/login?logout'),
        );
    }

    /** The registration id of a `GET {authorization_endpoint_base_uri}/{id}`, or null when the request is not one. */
    public function registrationIdOfAuthorizationRequest(Request $request): ?string
    {
        return $request->isMethod('GET') ? self::registrationIdUnder($this->authorizationEndpointBaseUri, $request) : null;
    }

    /** The registration id of a `GET {redirection_endpoint_base_uri}/{id}`, or null when the request is not one. */
    public function registrationIdOfRedirection(Request $request): ?string
    {
        return $request->isMethod('GET') ? self::registrationIdUnder($this->redirectionEndpointBaseUri, $request) : null;
    }

    private static function registrationIdUnder(string $baseUri, Request $request): ?string
    {
        $base = FormLoginSettings::path($baseUri);
        $path = FormLoginSettings::path($request->getPathInfo());
        if (! str_starts_with($path, $base.'/')) {
            return null;
        }

        $id = substr($path, strlen($base) + 1);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id) === 1 ? $id : null;
    }
}
