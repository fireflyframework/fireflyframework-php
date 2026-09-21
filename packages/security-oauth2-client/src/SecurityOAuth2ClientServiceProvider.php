<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered auto-configuration provider of firefly/security-oauth2-client (extra.laravel.providers).
 * Extending AutoConfiguration means its final register() records candidacy ONLY; the compiled manifests under
 * cache/ describe OAuth2ClientAutoConfiguration's gated #[Bean]s and the two #[Component] filters. The boot-pass
 * half — and the Http macro, which must exist at register() time — rides on SecurityOAuth2ClientWiringProvider.
 */
final class SecurityOAuth2ClientServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-security-oauth2-client-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-security-oauth2-client-context.php';
    }
}
