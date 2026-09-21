<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Client\Authorized\CacheOAuth2AuthorizedClientService;
use Firefly\Security\OAuth2\Client\Authorized\DefaultOAuth2AuthorizedClientManager;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientManager;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientService;
use Firefly\Security\OAuth2\Client\Authorized\SessionOAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\Discovery\OidcDiscovery;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdTokenDecoderFactory;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientProperties;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientPropertiesMapper;
use Firefly\Security\OAuth2\Client\Registration\PropertiesClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Token\DefaultOAuth2AccessTokenResponseClient;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessTokenResponseClient;
use Firefly\Security\OAuth2\Client\User\DefaultOAuth2UserService;
use Firefly\Security\OAuth2\Client\User\DefaultOidcUserService;
use Firefly\Security\OAuth2\Client\User\GrantedAuthoritiesMapper;
use Firefly\Security\OAuth2\Client\User\OAuth2UserService;
use Firefly\Security\OAuth2\Client\User\OidcUserService;
use Firefly\Security\OAuth2\Client\User\UserInfoClient;
use Firefly\Security\OAuth2\Client\Web\AuthorizationRequestRepository;
use Firefly\Security\OAuth2\Client\Web\Login\OAuth2LoginAuthenticationProvider;
use Firefly\Security\OAuth2\Client\Web\Login\OAuth2LoginPageLinks;
use Firefly\Security\OAuth2\Client\Web\Logout\OidcClientInitiatedLogoutSuccessHandler;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequestResolver;
use Firefly\Security\OAuth2\Client\Web\SessionAuthorizationRequestRepository;
use Firefly\Security\Web\Login\LoginPageLinks;
use Firefly\Security\Web\Logout\LogoutSuccessHandler;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * The opt-in bean source of the OAuth2 client. `firefly.security.oauth2.client.enabled` is the package master:
 * it gates the registrations, the discovery, the token client and the authorized-client manager — none of which
 * needs `firefly.security.enabled`, because a job that calls an API with client credentials has no inbound
 * security to speak of. The login half (`login.enabled`) additionally requires the security master flag, since
 * it signs into the master-gated session repository; OAuth2ClientWiringPass refuses the combination that lacks
 * it. #[Order(600)] is above SecurityAutoConfiguration's 500 so the security beans register first; every bean
 * here is #[ConditionalOnMissingBean] so an application's own binding always wins.
 */
#[Configuration]
#[Order(600)]
final class OAuth2ClientAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2ClientSettings::class)]
    public function oauth2ClientSettings(Config $config): OAuth2ClientSettings
    {
        return OAuth2ClientSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OidcDiscovery::class)]
    public function oidcDiscovery(Container $container, Cache $cache, OAuth2ClientSettings $settings): OidcDiscovery
    {
        return new OidcDiscovery($container, $cache, $settings);
    }

    /**
     * The registrations, from `registration.{id}`/`provider.{id}`. The mapper's static validation runs HERE,
     * at construction — and the EagerSingletonsPass constructs every bean at boot — so a registration that could
     * never work is a startup failure naming its key, never a 500 on the first login; OAuth2ClientWiringPass
     * resolves the bean explicitly as well, the belt SecurityWiringPass wears for the user store.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(ClientRegistrationRepository::class)]
    public function clientRegistrationRepository(Config $config, OidcDiscovery $discovery, OAuth2ClientSettings $settings): ClientRegistrationRepository
    {
        $mapper = new OAuth2ClientPropertiesMapper(OAuth2ClientProperties::fromConfig($config), $discovery, $settings);
        $mapper->validate();

        return new PropertiesClientRegistrationRepository($mapper);
    }

    /**
     * The token endpoint client and the id-token decoders, under the package master alone: the login uses
     * them, and so does the authorized-client manager a job drives without any inbound security.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2AccessTokenResponseClient::class)]
    public function oauth2AccessTokenResponseClient(Container $container, OAuth2ClientSettings $settings): OAuth2AccessTokenResponseClient
    {
        return new DefaultOAuth2AccessTokenResponseClient($container, $settings);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OidcIdTokenDecoderFactory::class)]
    public function oidcIdTokenDecoderFactory(Cache $cache, OAuth2ClientSettings $settings): OidcIdTokenDecoderFactory
    {
        return new OidcIdTokenDecoderFactory($cache, $settings);
    }

    /** Request-free, so a job can call an API as a person who signed in earlier or as the application itself. */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2AuthorizedClientService::class)]
    public function oauth2AuthorizedClientService(Cache $cache, Container $container, OAuth2ClientSettings $settings): OAuth2AuthorizedClientService
    {
        return new CacheOAuth2AuthorizedClientService($cache, $container, $settings);
    }

    /**
     * The manager, under the package master alone: client credentials need no inbound security and no login.
     * The session repository is optional — it exists only with the login half — so a job-only application
     * gets a manager that reads the cache service and nothing else.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2AuthorizedClientManager::class)]
    public function oauth2AuthorizedClientManager(ClientRegistrationRepository $registrations, OAuth2AccessTokenResponseClient $tokens, OAuth2AuthorizedClientService $service, OAuth2ClientSettings $settings, Container $container, ?OAuth2AuthorizedClientRepository $sessionClients = null): OAuth2AuthorizedClientManager
    {
        return new DefaultOAuth2AuthorizedClientManager($registrations, $tokens, $service, $settings, $container, $sessionClients);
    }

    /** Request-bound: the session, encrypted. Only a login puts anything in it, so it rides with the login half. */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2AuthorizedClientRepository::class)]
    public function oauth2AuthorizedClientRepository(Container $container): OAuth2AuthorizedClientRepository
    {
        return new SessionOAuth2AuthorizedClientRepository($container);
    }

    /**
     * THE LOGIN HALF. Gated by the package master AND `login.enabled` — not by the security master, because
     * none of these three needs a master-gated bean; the two filters, which do, carry the master condition
     * themselves, and OAuth2ClientWiringPass refuses a login without the master flag at boot.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2AuthorizationRequestResolver::class)]
    public function oauth2AuthorizationRequestResolver(): OAuth2AuthorizationRequestResolver
    {
        return new OAuth2AuthorizationRequestResolver;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(AuthorizationRequestRepository::class)]
    public function authorizationRequestRepository(): AuthorizationRequestRepository
    {
        return new SessionAuthorizationRequestRepository;
    }

    /** What firefly/security's login page lists: every authorization-code registration, as a "Sign in with" button. */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(LoginPageLinks::class)]
    public function loginPageLinks(ClientRegistrationRepository $registrations, OAuth2ClientSettings $settings, ?LoggerInterface $logger = null): LoginPageLinks
    {
        return new OAuth2LoginPageLinks($registrations, $settings, $logger);
    }

    /**
     * RP-initiated logout, on firefly/security's LogoutSuccessHandler port — the LogoutFilter asks it before the
     * session is invalidated, which is what lets it read the id token. Triple-gated: the package master, the
     * login (OAuth2ClientWiringPass refuses `logout.oidc_initiated` without it) and the key itself.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.logout.oidc_initiated', havingValue: 'true')]
    #[ConditionalOnMissingBean(LogoutSuccessHandler::class)]
    public function logoutSuccessHandler(ClientRegistrationRepository $registrations, OAuth2AuthorizedClientRepository $authorizedClients, OAuth2ClientSettings $settings, Container $container, ?LoggerInterface $logger = null): LogoutSuccessHandler
    {
        return new OidcClientInitiatedLogoutSuccessHandler($registrations, $authorizedClients, $settings, $container, $logger);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(UserInfoClient::class)]
    public function userInfoClient(Container $container, OAuth2ClientSettings $settings): UserInfoClient
    {
        return new UserInfoClient($container, $settings);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2UserService::class)]
    public function oauth2UserService(UserInfoClient $userInfo): OAuth2UserService
    {
        return new DefaultOAuth2UserService($userInfo);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OidcUserService::class)]
    public function oidcUserService(UserInfoClient $userInfo): OidcUserService
    {
        return new DefaultOidcUserService($userInfo);
    }

    /**
     * The GrantedAuthoritiesMapper is optional and has no default: an application binds one as a #[Bean] to
     * turn `groups`/`roles` claims into ROLE_*; without one the granted authorities are used as they are.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2LoginAuthenticationProvider::class)]
    public function oauth2LoginAuthenticationProvider(OAuth2AccessTokenResponseClient $tokens, OidcIdTokenDecoderFactory $decoders, OidcUserService $oidcUsers, OAuth2UserService $oauth2Users, ?GrantedAuthoritiesMapper $authoritiesMapper = null): OAuth2LoginAuthenticationProvider
    {
        return new OAuth2LoginAuthenticationProvider($tokens, $decoders, $oidcUsers, $oauth2Users, $authoritiesMapper);
    }
}
