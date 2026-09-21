<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\Authorized\CacheOAuth2AuthorizedClientService;
use Firefly\Security\OAuth2\Client\Authorized\DefaultOAuth2AuthorizedClientManager;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClient;
use Firefly\Security\OAuth2\Client\Authorized\SessionOAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\InMemoryClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Token\ClientAuthorizationRequiredException;
use Firefly\Security\OAuth2\Client\Token\DefaultOAuth2AccessTokenResponseClient;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthorizationException;
use Firefly\Testing\FireflyTestCase;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;

uses(FireflyTestCase::class);

const MANAGER_ISSUER = 'https://idp.example.test/manager';

/** A fresh fake on a fresh Http factory (see fakeIdp() in the token client test for why). */
function managerIdp(): FakeAuthorizationServer
{
    $http = new HttpFactory;
    Http::swap($http);

    return (new FakeAuthorizationServer(MANAGER_ISSUER))->fakeBackChannel($http);
}

/** @param list<string> $scopes */
function managerRegistration(string $id, AuthorizationGrantType $grant, array $scopes): ClientRegistration
{
    return new ClientRegistration($id, FakeAuthorizationServer::CLIENT_ID, FakeAuthorizationServer::CLIENT_SECRET, ClientAuthenticationMethod::ClientSecretBasic, $grant, '{baseUrl}/cb', $scopes, $id,
        new ProviderDetails(MANAGER_ISSUER.'/authorize', MANAGER_ISSUER.'/token', MANAGER_ISSUER.'/jwks', MANAGER_ISSUER.'/userinfo', 'sub', MANAGER_ISSUER));
}

function managerService(): CacheOAuth2AuthorizedClientService
{
    return new CacheOAuth2AuthorizedClientService(new CacheRepository(new ArrayStore), app(), new OAuth2ClientSettings);
}

function manager(CacheOAuth2AuthorizedClientService $service, ?SessionOAuth2AuthorizedClientRepository $session = null): DefaultOAuth2AuthorizedClientManager
{
    $settings = new OAuth2ClientSettings;

    return new DefaultOAuth2AuthorizedClientManager(
        new InMemoryClientRegistrationRepository([managerRegistration('svc', AuthorizationGrantType::ClientCredentials, ['orders:read']), managerRegistration('fake', AuthorizationGrantType::AuthorizationCode, ['openid', 'profile'])]),
        new DefaultOAuth2AccessTokenResponseClient(app(), $settings),
        $service,
        $settings,
        app(),
        $session,
    );
}

/** A user-bound client as a login would have stored it: the fake's own refresh token, and an access token that expires when asked. */
function userClient(FakeAuthorizationServer $idp, int $expiresAt, bool $refreshable = true): OAuth2AuthorizedClient
{
    $response = (new DefaultOAuth2AccessTokenResponseClient(app(), new OAuth2ClientSettings))
        ->authorizationCode(managerRegistration('fake', AuthorizationGrantType::AuthorizationCode, ['openid', 'profile']), $idp->issueAuthorizationCode('https://app/cb', ['openid', 'profile']), 'https://app/cb', null);

    return new OAuth2AuthorizedClient('fake', 'ada', new OAuth2AccessToken('old-access-token', 1, $expiresAt, ['openid', 'profile']), $refreshable ? $response->refreshToken : null, 'raw.id.token');
}

it('fetches client credentials once, answers from the cache, and fetches again when the token is about to expire', function () {
    /** @var FireflyTestCase $this */
    $idp = managerIdp();
    $manager = manager(managerService());

    $first = $manager->authorize('svc');
    $second = $manager->authorize('svc');

    expect($first->accessToken->tokenValue)->toBe($idp->issuedAccessTokens[0])
        ->and($first->principalName)->toBe(FakeAuthorizationServer::CLIENT_ID)
        ->and($first->refreshToken)->toBeNull()
        ->and($second)->toEqual($first)
        ->and($idp->tokenRequests)->toHaveCount(1)
        ->and($idp->tokenRequests[0]['form'])->toMatchArray(['grant_type' => 'client_credentials', 'scope' => 'orders:read']);

    // 3600 s of life, 60 s of skew: 3541 s later it counts as expired.
    $this->travel(3541)->seconds();
    $third = $manager->authorize('svc');

    expect($third->accessToken->tokenValue)->toBe($idp->issuedAccessTokens[1])
        ->and($idp->tokenRequests)->toHaveCount(2)
        ->and($manager->authorize('svc', 'job-7')->principalName)->toBe('job-7')
        ->and($idp->tokenRequests)->toHaveCount(3)
        ->and(fn () => $manager->authorize('nope'))->toThrow(ConfigurationException::class, 'firefly.security.oauth2.client.registration');
});

it('refuses a user-bound client nobody authorized, returns a live one, refreshes an expiring one and drops one it cannot renew', function () {
    /** @var FireflyTestCase $this */
    $idp = managerIdp();
    $service = managerService();
    $manager = manager($service);

    expect(fn () => $manager->authorize('fake'))->toThrow(ClientAuthorizationRequiredException::class, '[fake]');
    expect(fn () => $manager->authorize('fake', 'ada'))->toThrow(ClientAuthorizationRequiredException::class);

    $service->saveAuthorizedClient(userClient($idp, time() + 3600));
    $live = $manager->authorize('fake', 'ada');
    expect($live->accessToken->tokenValue)->toBe('old-access-token')
        ->and($idp->tokenRequests)->toHaveCount(1);

    $this->actingAsPrincipal('ada');
    expect($manager->authorize('fake')->accessToken->tokenValue)->toBe('old-access-token');

    $service->saveAuthorizedClient(userClient($idp, time() + 30));
    $refreshed = $manager->authorize('fake');
    expect($refreshed->accessToken->tokenValue)->toBe($idp->issuedAccessTokens[count($idp->issuedAccessTokens) - 1])
        ->and($refreshed->accessToken->tokenValue)->not->toBe('old-access-token')
        ->and($refreshed->refreshToken?->tokenValue)->toStartWith('rt-')
        ->and($refreshed->idToken)->toBe('raw.id.token')
        ->and($idp->lastTokenRequest()['grant_type'])->toBe('refresh_token')
        ->and($service->loadAuthorizedClient('fake', 'ada'))->toEqual($refreshed)
        ->and($manager->authorize('fake'))->toEqual($refreshed);

    $service->saveAuthorizedClient(userClient($idp, time() + 30, refreshable: false));
    expect(fn () => $manager->authorize('fake'))->toThrow(ClientAuthorizationRequiredException::class)
        ->and($service->loadAuthorizedClient('fake', 'ada'))->toBeNull();

    $service->saveAuthorizedClient(userClient($idp, time() + 30));
    $idp->refuseToken('invalid_grant', 'revoked');
    expect(fn () => $manager->authorize('fake'))->toThrow(OAuth2AuthorizationException::class, 'invalid_grant')
        ->and($service->loadAuthorizedClient('fake', 'ada'))->toBeNull();
    $idp->refuseToken('');
    expect(fn () => $manager->authorize('fake'))->toThrow(ClientAuthorizationRequiredException::class);
});

it('reads and updates the session entry when the current request has a session', function () {
    /** @var FireflyTestCase $this */
    $idp = managerIdp();
    $session = new SessionOAuth2AuthorizedClientRepository(app());
    $service = managerService();
    $manager = manager($service, $session);

    $request = Request::create('http://localhost/api/orders', 'GET');
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();
    $request->setLaravelSession($store);
    $session->saveAuthorizedClient(userClient($idp, time() + 30), $request);
    $this->app()->instance('request', $request);

    $refreshed = $manager->authorize('fake');

    expect($refreshed->accessToken->tokenValue)->not->toBe('old-access-token')
        ->and($session->loadAuthorizedClient('fake', $request))->toEqual($refreshed)
        ->and($service->loadAuthorizedClient('fake', 'ada'))->toEqual($refreshed)
        ->and($idp->tokenRequests)->toHaveCount(2)
        ->and($manager->authorize('fake'))->toEqual($refreshed)
        ->and($idp->tokenRequests)->toHaveCount(2);
});
