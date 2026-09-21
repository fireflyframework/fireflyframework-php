<?php

declare(strict_types=1);

use Firefly\Security\Core\GrantedAuthority;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdTokenDecoderFactory;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Token\DefaultOAuth2AccessTokenResponseClient;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\User\DefaultOAuth2UserService;
use Firefly\Security\OAuth2\Client\User\DefaultOidcUserService;
use Firefly\Security\OAuth2\Client\User\GrantedAuthoritiesMapper;
use Firefly\Security\OAuth2\Client\User\OAuth2AuthenticationToken;
use Firefly\Security\OAuth2\Client\User\OidcUser;
use Firefly\Security\OAuth2\Client\User\OidcUserAuthority;
use Firefly\Security\OAuth2\Client\User\UserInfoClient;
use Firefly\Security\OAuth2\Client\Web\Login\OAuth2LoginAuthenticationProvider;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequest;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

const PROVIDER_ISSUER = 'https://idp.example.test/realm';

/** A fresh fake on a fresh Http factory (see fakeIdp() in the token client test for why). */
function providerIdp(): FakeAuthorizationServer
{
    $http = new HttpFactory;
    Http::swap($http);

    return (new FakeAuthorizationServer(PROVIDER_ISSUER))->fakeBackChannel($http);
}

/** @param list<string> $scopes */
function providerRegistration(array $scopes = ['openid', 'profile', 'email'], string $nameAttribute = 'sub'): ClientRegistration
{
    return new ClientRegistration('fake', FakeAuthorizationServer::CLIENT_ID, FakeAuthorizationServer::CLIENT_SECRET, ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', $scopes, 'Fake',
        new ProviderDetails(PROVIDER_ISSUER.'/authorize', PROVIDER_ISSUER.'/token', PROVIDER_ISSUER.'/jwks', PROVIDER_ISSUER.'/userinfo', $nameAttribute, PROVIDER_ISSUER));
}

function loginProvider(?GrantedAuthoritiesMapper $mapper = null): OAuth2LoginAuthenticationProvider
{
    $settings = new OAuth2ClientSettings;
    $userInfo = new UserInfoClient(app(), $settings);

    return new OAuth2LoginAuthenticationProvider(
        new DefaultOAuth2AccessTokenResponseClient(app(), $settings),
        new OidcIdTokenDecoderFactory(new CacheRepository(new ArrayStore), $settings),
        new DefaultOidcUserService($userInfo),
        new DefaultOAuth2UserService($userInfo),
        $mapper,
    );
}

/**
 * @param  list<string>  $scopes
 * @return array{0: OAuth2AuthorizationRequest, 1: string}
 */
function authorizationRequestFor(FakeAuthorizationServer $idp, array $scopes, ?string $nonce = 'n-1'): array
{
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $request = new OAuth2AuthorizationRequest('fake', PROVIDER_ISSUER.'/authorize', FakeAuthorizationServer::CLIENT_ID, 'https://app.example.test/login/oauth2/code/fake', $scopes, 'st', $nonce, $verifier);
    $code = $idp->issueAuthorizationCode($request->redirectUri, $scopes, $nonce, OAuth2AuthorizationRequest::codeChallenge($verifier));

    return [$request, $code];
}

it('signs an OIDC user in: code exchanged, id token verified, userinfo loaded, authorities mapped, the authorized client built', function () {
    $idp = providerIdp()->withUser(['sub' => 'u-1', 'groups' => ['admins']]);
    [$request, $code] = authorizationRequestFor($idp, ['openid', 'profile', 'email']);
    $mapper = new class implements GrantedAuthoritiesMapper
    {
        public function mapAuthorities(array $authorities): array
        {
            $mapped = $authorities;
            foreach ($authorities as $authority) {
                if ($authority instanceof OidcUserAuthority && in_array('admins', (array) $authority->getIdToken()->getClaim('groups'), true)) {
                    $mapped[] = new SimpleGrantedAuthority('ROLE_ADMIN');
                }
            }

            return $mapped;
        }
    };

    $login = loginProvider($mapper)->authenticate(providerRegistration(), $request, $code);

    $user = $login->user;
    expect($user)->toBeInstanceOf(OidcUser::class)
        ->and($user->getName())->toBe('u-1')
        ->and($user instanceof OidcUser ? $user->getEmail() : null)->toBe('ada@example.com')
        ->and($user instanceof OidcUser ? $user->getIdToken()->getTokenValue() : null)->toBe($idp->issuedIdTokens[0])
        ->and($user instanceof OidcUser ? $user->getIdToken()->getNonce() : null)->toBe('n-1')
        ->and(array_map(static fn (GrantedAuthority $a): string => $a->getAuthority(), $user->getAuthorities()))->toBe(['OIDC_USER', 'SCOPE_openid', 'SCOPE_profile', 'SCOPE_email'])
        ->and($login->authentication->authorityStrings())->toBe(['OIDC_USER', 'SCOPE_openid', 'SCOPE_profile', 'SCOPE_email', 'ROLE_ADMIN'])
        ->and($login->authentication->getPrincipal())->toBe($user)
        ->and(OAuth2AuthenticationToken::registrationId($login->authentication))->toBe('fake')
        ->and($login->authorizedClient->registrationId)->toBe('fake')
        ->and($login->authorizedClient->principalName)->toBe('u-1')
        ->and($login->authorizedClient->accessToken->tokenValue)->toBe($idp->issuedAccessTokens[0])
        ->and($login->authorizedClient->refreshToken?->tokenValue)->toStartWith('rt-')
        ->and($login->authorizedClient->idToken)->toBe($idp->issuedIdTokens[0])
        ->and($idp->userInfoRequests)->toBe([$idp->issuedAccessTokens[0]])
        ->and($idp->jwksRequests)->toBe(1);
});

it('refuses a wrong nonce, a foreign audience, a foreign issuer, a foreign signature, a missing id token and a refused exchange — each with its code', function () {
    $refused = static function (FakeAuthorizationServer $idp, ?string $nonce = 'n-1', ?ClientRegistration $registration = null): string {
        [$request, $code] = authorizationRequestFor($idp, ['openid'], $nonce);
        try {
            loginProvider()->authenticate($registration ?? providerRegistration(['openid']), $request, $code);
        } catch (OAuth2AuthenticationException $e) {
            return $e->error->errorCode;
        }

        return 'accepted';
    };

    expect($refused(providerIdp()->overrideIdTokenClaims(['nonce' => 'other'])))->toBe('invalid_nonce')
        ->and($refused(providerIdp()->overrideIdTokenClaims(['aud' => 'another-app'])))->toBe('invalid_id_token')
        ->and($refused(providerIdp()->overrideIdTokenClaims(['iss' => 'https://evil.example.test'])))->toBe('invalid_id_token')
        ->and($refused(providerIdp()->signWithUnknownKey()))->toBe('invalid_id_token')
        ->and($refused(providerIdp()->overrideIdTokenClaims(['exp' => 1])))->toBe('invalid_id_token')
        ->and($refused(providerIdp()->refuseToken('invalid_grant', 'nope')))->toBe('invalid_grant')
        ->and($refused(providerIdp()))->toBe('accepted');

    // A registration that requests openid but gets no id_token back: the fake issues one only for the openid scope, so ask for a code without it.
    $idp = providerIdp();
    $request = new OAuth2AuthorizationRequest('fake', PROVIDER_ISSUER.'/authorize', FakeAuthorizationServer::CLIENT_ID, 'https://app/cb', ['openid'], 'st', 'n-1', null);
    $code = $idp->issueAuthorizationCode('https://app/cb', ['profile'], null, null);
    try {
        loginProvider()->authenticate(providerRegistration(['openid']), $request, $code);
        $outcome = 'accepted';
    } catch (OAuth2AuthenticationException $e) {
        $outcome = $e->error->errorCode.': '.$e->error->description;
    }
    expect($outcome)->toBe('invalid_id_token: The token response carries no id_token.');
});

it('signs a plain OAuth2 user in from userinfo when openid is not requested', function () {
    $idp = providerIdp()->withUser(['sub' => 'u-2', 'login' => 'ada-l']);
    [$request, $code] = authorizationRequestFor($idp, ['profile'], null);

    $login = loginProvider()->authenticate(providerRegistration(['profile'], 'login'), $request, $code);

    expect($login->user)->not->toBeInstanceOf(OidcUser::class)
        ->and($login->user->getName())->toBe('ada-l')
        ->and($login->authentication->authorityStrings())->toBe(['OAUTH2_USER', 'SCOPE_profile'])
        ->and($login->authorizedClient->idToken)->toBeNull()
        ->and($idp->jwksRequests)->toBe(0);
});
