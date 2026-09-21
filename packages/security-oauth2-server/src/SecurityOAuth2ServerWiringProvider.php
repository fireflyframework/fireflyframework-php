<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Security\OAuth2\Server\Boot\OAuth2AuthorizationPurgeSchedulePass;
use Firefly\Security\OAuth2\Server\Boot\OAuth2ServerWiringPass;

/**
 * The boot-pass half of firefly/security-oauth2-server (cannot ride on SecurityOAuth2ServerServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Contributes OAuth2ServerWiringPass (order 210,
 * after SecurityWiringPass's 200: the server's refusals assume the core's guards already ran) and
 * OAuth2AuthorizationPurgeSchedulePass (InfrastructureStart, 10: the purge descriptor joins the ScheduledManifest
 * before the eager singletons capture it). Both this and SecurityOAuth2ServerServiceProvider are in
 * extra.laravel.providers.
 */
final class SecurityOAuth2ServerWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new OAuth2ServerWiringPass, new OAuth2AuthorizationPurgeSchedulePass];
    }
}
