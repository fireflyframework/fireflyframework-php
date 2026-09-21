<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\Authorized\CacheOAuth2AuthorizedClientService;
use Firefly\Security\OAuth2\Client\Authorized\DefaultOAuth2AuthorizedClientManager;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientManager;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientService;
use Firefly\Security\OAuth2\Client\Authorized\SessionOAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\Discovery\OidcDiscovery;
use Firefly\Security\OAuth2\Client\Discovery\ProviderDiscoveryException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Registration\PropertiesClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\SecurityOAuth2ClientServiceProvider;
use Firefly\Security\OAuth2\Client\SecurityOAuth2ClientWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Boots the REAL providers of security and of this package on a bare container (the RealProviderBootTest
 * idiom): what the gates bind, and what a contradictory configuration is refused with. A session driver and
 * the HTTP kernel are seeded because OAuth2 login implies the session exactly as form login does
 * (SessionSecuritySettings): SessionSecurityBootstrap, which runs BEFORE this package's pass, refuses a
 * session-backed boot without a driver and pushes the session middleware onto the kernel — and this suite
 * is about which of the package's own flags is missing, not about that.
 *
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this boot
 * @param  array<class-string,object>  $bindings  instances bound before the providers register (a faked Http factory, say)
 */
function bootOAuth2ClientAppWith(array $security, array $bindings = []): Application
{
    return fireflyApplication(
        config: ['session' => ['driver' => 'array'], 'firefly' => ['cqrs' => [], 'security' => $security]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class, SecurityOAuth2ClientServiceProvider::class, SecurityOAuth2ClientWiringProvider::class],
        bindings: $bindings,
        needs: ['cache', 'http'],
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

it('refuses at boot a registration that could never work, and a login with nothing to sign in through', function () {
    expect(fn () => bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'registration' => ['github' => ['client_id' => 'x']]]]]))
        ->toThrow(ConfigurationException::class, 'firefly.security.oauth2.client.registration.github.client_secret')
        ->and(fn () => bootOAuth2ClientAppWith(['enabled' => true, 'oauth2' => ['client' => ['enabled' => true, 'login' => ['enabled' => true]]]]))
        ->toThrow(ConfigurationException::class, 'no client registration');
});

it('binds the registration repository, resolved from the config, when the package is on', function () {
    /** @var ApplicationContext $context */
    $context = bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'registration' => ['github' => ['client_id' => 'x', 'client_secret' => 's']]]]])->make(ApplicationContext::class);

    /** @var ClientRegistrationRepository $repository */
    $repository = $context->get(ClientRegistrationRepository::class);

    expect($repository)->toBeInstanceOf(PropertiesClientRegistrationRepository::class)
        ->and($repository->registrationIds())->toBe(['github'])
        ->and($context->has(OidcDiscovery::class))->toBeTrue();
});

it('binds the cache-backed authorized-client service under the package master alone, and the session repository only with a login', function () {
    $github = ['github' => ['client_id' => 'x', 'client_secret' => 's']];
    /** @var ApplicationContext $client */
    $client = bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'registration' => $github]]])->make(ApplicationContext::class);
    /** @var ApplicationContext $login */
    $login = bootOAuth2ClientAppWith(['enabled' => true, 'oauth2' => ['client' => ['enabled' => true, 'login' => ['enabled' => true], 'registration' => $github]]])->make(ApplicationContext::class);

    // The service is request-free — a job with client credentials needs it and no inbound security — while the
    // repository is only ever filled by a login, so it rides with the login half.
    expect($client->get(OAuth2AuthorizedClientService::class))->toBeInstanceOf(CacheOAuth2AuthorizedClientService::class)
        ->and($client->has(OAuth2AuthorizedClientRepository::class))->toBeFalse()
        ->and($login->get(OAuth2AuthorizedClientService::class))->toBeInstanceOf(CacheOAuth2AuthorizedClientService::class)
        ->and($login->get(OAuth2AuthorizedClientRepository::class))->toBeInstanceOf(SessionOAuth2AuthorizedClientRepository::class);
});

it('fetches nothing at boot by default, and with discovery.eager resolves every issuer — a dead one failing the boot', function () {
    $issuer = 'https://sso.example.com/realms/corp';
    $registration = ['corp' => ['provider' => 'keycloak', 'client_id' => 'portal', 'client_secret' => 's']];
    $provider = ['keycloak' => ['issuer_uri' => $issuer]];
    $document = ['issuer' => $issuer, 'authorization_endpoint' => $issuer.'/auth', 'token_endpoint' => $issuer.'/token', 'jwks_uri' => $issuer.'/certs'];

    // Lazy (the default): the boot validates statically and never asks the provider.
    $lazy = (new HttpFactory)->preventStrayRequests();
    $lazy->fake([$issuer.'/.well-known/openid-configuration' => HttpFactory::response($document)]);
    bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'registration' => $registration, 'provider' => $provider]]], [HttpFactory::class => $lazy]);
    $lazy->assertNothingSent();

    // Eager: the document is fetched during the boot, once, and the registration comes out resolved.
    $eager = (new HttpFactory)->preventStrayRequests();
    $eager->fake([$issuer.'/.well-known/openid-configuration' => HttpFactory::response($document)]);
    /** @var ApplicationContext $context */
    $context = bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'discovery' => ['eager' => true], 'registration' => $registration, 'provider' => $provider]]], [HttpFactory::class => $eager])->make(ApplicationContext::class);
    $eager->assertSentCount(1);
    /** @var ClientRegistrationRepository $repository */
    $repository = $context->get(ClientRegistrationRepository::class);
    expect($repository->findByRegistrationId('corp')?->providerDetails->tokenUri)->toBe($issuer.'/token');

    // Eager with an issuer that does not answer: the boot fails as a 503-typed discovery failure naming the host.
    $dead = (new HttpFactory)->preventStrayRequests();
    $dead->fake([$issuer.'/.well-known/openid-configuration' => HttpFactory::response('', 503)]);
    expect(fn () => bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'discovery' => ['eager' => true], 'registration' => $registration, 'provider' => $provider]]], [HttpFactory::class => $dead]))
        ->toThrow(ProviderDiscoveryException::class, 'sso.example.com');
});

it('binds the manager under the package master alone, and registers the Http macro unless http.macro is off', function () {
    HttpFactory::flushMacros();
    /** @var ApplicationContext $context */
    $context = bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'registration' => ['github' => ['client_id' => 'x', 'client_secret' => 's']]]]])->make(ApplicationContext::class);

    expect($context->get(OAuth2AuthorizedClientManager::class))->toBeInstanceOf(DefaultOAuth2AuthorizedClientManager::class)
        ->and(HttpFactory::hasMacro('oauth2Client'))->toBeTrue();

    HttpFactory::flushMacros();
    bootOAuth2ClientAppWith(['oauth2' => ['client' => ['enabled' => true, 'http' => ['macro' => false]]]]);
    expect(HttpFactory::hasMacro('oauth2Client'))->toBeFalse();

    bootOAuth2ClientAppWith([]);
    expect(HttpFactory::hasMacro('oauth2Client'))->toBeTrue();
});
