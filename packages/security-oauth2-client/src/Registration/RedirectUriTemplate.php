<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/**
 * Spring's redirect-uri template, `{baseUrl}/login/oauth2/code/{registrationId}`. `{baseUrl}` is the
 * application's root as Laravel's UrlGenerator::to('/') builds it — an absolute URL that honours a forced scheme
 * or root behind a TLS-terminating proxy, exactly what the provider must be sent back to — with any trailing
 * slash removed so `{baseUrl}/login/...` never doubles it.
 */
final class RedirectUriTemplate
{
    public static function expand(string $template, string $baseUrl, string $registrationId): string
    {
        return strtr($template, [
            '{baseUrl}' => rtrim($baseUrl, '/'),
            '{registrationId}' => $registrationId,
        ]);
    }
}
