<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\SecurityOAuth2ClientServiceProvider;
use Firefly\Security\OAuth2\Client\SecurityOAuth2ClientWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Foundation\Application;

/**
 * Boots the REAL providers of security and of this package on a bare container (the RealProviderBootTest
 * idiom): what the gates bind, and what a contradictory configuration is refused with.
 *
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this boot
 */
function bootOAuth2ClientAppWith(array $security): Application
{
    return fireflyApplication(
        config: ['firefly' => ['cqrs' => [], 'security' => $security]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class, SecurityOAuth2ClientServiceProvider::class, SecurityOAuth2ClientWiringProvider::class],
        needs: ['cache'],
    );
}

it('binds nothing when the package is off, and the settings when it is on', function () {
    /** @var ApplicationContext $off */
    $off = bootOAuth2ClientAppWith([])->make(ApplicationContext::class);
    /** @var ApplicationContext $on */
    $on = bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true]]])->make(ApplicationContext::class);

    expect($off->has(OAuth2ClientSettings::class))->toBeFalse()
        ->and($on->has(OAuth2ClientSettings::class))->toBeTrue()
        ->and($on->get(OAuth2ClientSettings::class))->toBeInstanceOf(OAuth2ClientSettings::class);
});

it('refuses to boot a login without the package master, without the security master, and an OIDC logout without a login', function () {
    expect(fn () => bootOAuth2ClientAppWith(['enabled' => true, 'oauth2' => ['client' => ['login' => ['enabled' => true]]]]))
        ->toThrow(ConfigurationException::class, 'firefly.security.oauth2.client.enabled')
        ->and(fn () => bootOAuth2ClientAppWith(['enabled' => false, 'oauth2' => ['client' => ['enabled' => true, 'login' => ['enabled' => true]]]]))
        ->toThrow(ConfigurationException::class, 'firefly.security.enabled')
        ->and(fn () => bootOAuth2ClientAppWith(['enabled' => true, 'oauth2' => ['client' => ['enabled' => true, 'logout' => ['oidc_initiated' => true]]]]))
        ->toThrow(ConfigurationException::class, 'firefly.security.oauth2.client.login.enabled');
});
