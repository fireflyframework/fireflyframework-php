<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Security\OAuth2\Client\Boot\OAuth2ClientWiringPass;

/**
 * The boot-pass half of firefly/security-oauth2-client (cannot ride on SecurityOAuth2ClientServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Contributes OAuth2ClientWiringPass (order 210,
 * right after SecurityWiringPass at 200, so the security master flag's own guards have already run). Both this
 * and SecurityOAuth2ClientServiceProvider are in extra.laravel.providers.
 */
final class SecurityOAuth2ClientWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new OAuth2ClientWiringPass];
    }
}
