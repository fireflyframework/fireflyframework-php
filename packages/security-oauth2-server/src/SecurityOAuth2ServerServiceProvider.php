<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered auto-configuration provider of firefly/security-oauth2-server (extra.laravel.providers). Extending
 * AutoConfiguration means its final register() records candidacy ONLY; the compiled manifests describe
 * OAuth2ServerAutoConfiguration's gated #[Bean]s and the #[Component] filter, endpoints, purge task and actuator
 * endpoint. The boot-pass half rides on SecurityOAuth2ServerWiringProvider (never here).
 */
final class SecurityOAuth2ServerServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-security-oauth2-server-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-security-oauth2-server-context.php';
    }
}
