<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdToken;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\User\DefaultOAuth2UserService;
use Firefly\Security\OAuth2\Client\User\DefaultOidcUserService;
use Firefly\Security\OAuth2\Client\User\OAuth2UserRequest;
use Firefly\Security\OAuth2\Client\User\OidcUserRequest;
use Firefly\Security\OAuth2\Client\User\UserInfoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/** @param list<string> $scopes */
function servicesRegistration(?string $userInfoUri = 'https://idp.example.test/userinfo', string $nameAttribute = 'sub', array $scopes = ['openid', 'profile']): ClientRegistration
{
    return new ClientRegistration('fake', 'app', 's', ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', $scopes, 'Fake', new ProviderDetails('https://a', 'https://t', 'https://j', $userInfoUri, $nameAttribute, 'https://idp.example.test'));
}

/** @param list<string> $scopes */
function accessToken(array $scopes = ['openid', 'profile']): OAuth2AccessToken
{
    return new OAuth2AccessToken('at-1', 1, 3601, $scopes);
}

function userInfoClient(): UserInfoClient
{
    return new UserInfoClient(app(), new OAuth2ClientSettings);
}

function serviceRefusal(callable $attempt): string
{
    try {
        $attempt();
    } catch (OAuth2AuthenticationException $e) {
        return $e->error->errorCode;
    }

    return 'accepted';
}

it('loads an OIDC user from the id token, fetching userinfo with the bearer when a profile scope was granted', function () {
    Http::fake(['https://idp.example.test/userinfo' => Http::response(['sub' => 'u-1', 'email' => 'ada@example.com', 'preferred_username' => 'ada'])]);
    $idToken = new OidcIdToken('raw', ['iss' => 'https://idp.example.test', 'sub' => 'u-1', 'aud' => 'app', 'iat' => 1, 'exp' => 2, 'name' => 'Ada']);

    $user = (new DefaultOidcUserService(userInfoClient()))->loadUser(new OidcUserRequest(servicesRegistration(nameAttribute: 'preferred_username'), accessToken(), $idToken));

    expect($user->getName())->toBe('ada')
        ->and($user->getFullName())->toBe('Ada')
        ->and($user->getUserInfo())->not->toBeNull()
        ->and(array_map(static fn ($a): string => $a->getAuthority(), $user->getAuthorities()))->toBe(['OIDC_USER', 'SCOPE_openid', 'SCOPE_profile']);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://idp.example.test/userinfo' && $request->hasHeader('Authorization', 'Bearer at-1'));

    // No profile/email/address/phone scope, or no endpoint: the id token alone names the user, and nothing is fetched.
    $idOnly = (new DefaultOidcUserService(userInfoClient()))->loadUser(new OidcUserRequest(servicesRegistration(), accessToken(['openid']), $idToken));
    $noEndpoint = (new DefaultOidcUserService(userInfoClient()))->loadUser(new OidcUserRequest(servicesRegistration(null), accessToken(), $idToken));
    expect($idOnly->getUserInfo())->toBeNull()->and($noEndpoint->getUserInfo())->toBeNull()->and($idOnly->getName())->toBe('u-1');
    Http::assertSentCount(1);
});

it('refuses a userinfo whose subject differs, a userinfo that fails, and a principal without its name attribute', function () {
    Http::fake([
        'https://idp.example.test/userinfo' => Http::response(['sub' => 'someone-else']),
        'https://down.example.test/userinfo' => Http::response('', 500),
        'https://html.example.test/userinfo' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $idToken = new OidcIdToken('raw', ['iss' => 'https://idp.example.test', 'sub' => 'u-1', 'aud' => 'app', 'iat' => 1, 'exp' => 2]);
    $service = new DefaultOidcUserService(userInfoClient());

    expect(serviceRefusal(fn () => $service->loadUser(new OidcUserRequest(servicesRegistration(), accessToken(), $idToken))))->toBe('invalid_user_info_response')
        ->and(serviceRefusal(fn () => $service->loadUser(new OidcUserRequest(servicesRegistration('https://down.example.test/userinfo'), accessToken(), $idToken))))->toBe('invalid_user_info_response')
        ->and(serviceRefusal(fn () => $service->loadUser(new OidcUserRequest(servicesRegistration('https://html.example.test/userinfo'), accessToken(), $idToken))))->toBe('invalid_user_info_response')
        ->and(serviceRefusal(fn () => $service->loadUser(new OidcUserRequest(servicesRegistration(null, 'preferred_username'), accessToken(), $idToken))))->toBe('missing_user_name_attribute');
});

it('loads a plain OAuth2 user from userinfo alone, named by the attribute, and refuses without an endpoint or the attribute', function () {
    Http::fake(['https://idp.example.test/userinfo' => Http::response(['id' => 583231, 'login' => 'octocat'])]);
    $service = new DefaultOAuth2UserService(userInfoClient());

    $user = $service->loadUser(new OAuth2UserRequest(servicesRegistration(nameAttribute: 'id', scopes: ['read:user']), accessToken(['read:user'])));

    expect($user->getName())->toBe('583231')
        ->and($user->getAttribute('login'))->toBe('octocat')
        ->and(array_map(static fn ($a): string => $a->getAuthority(), $user->getAuthorities()))->toBe(['OAUTH2_USER', 'SCOPE_read:user'])
        ->and(serviceRefusal(fn () => $service->loadUser(new OAuth2UserRequest(servicesRegistration(null, 'id', ['read:user']), accessToken(['read:user'])))))->toBe('missing_user_info_uri')
        ->and(serviceRefusal(fn () => $service->loadUser(new OAuth2UserRequest(servicesRegistration(nameAttribute: 'email', scopes: ['read:user']), accessToken(['read:user'])))))->toBe('missing_user_name_attribute');
});
