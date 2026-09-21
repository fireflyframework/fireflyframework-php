<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClient;
use Firefly\Security\OAuth2\Client\Authorized\SessionOAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\InMemoryClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Security\OAuth2\Client\User\DefaultOAuth2User;
use Firefly\Security\OAuth2\Client\User\OAuth2AuthenticationToken;
use Firefly\Security\OAuth2\Client\Web\Logout\OidcClientInitiatedLogoutSuccessHandler;
use Firefly\Security\Tests\Support\RecordingLogger;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

// FireflyTestCase, not the bare Testbench case: the session repository encrypts with the application key, and
// the Firefly harness is what fixes one.
uses(FireflyTestCase::class);

function logoutRegistration(string $id, ?string $endSession): ClientRegistration
{
    return new ClientRegistration($id, 'app-'.$id, 's', ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', ['openid'], $id, new ProviderDetails('https://idp/a', 'https://idp/t', 'https://idp/j', null, 'sub', 'https://idp', $endSession));
}

function logoutRequest(): Request
{
    $request = Request::create('http://localhost/logout', 'POST');
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();
    $request->setLaravelSession($store);

    return $request;
}

/** @return array{0: OidcClientInitiatedLogoutSuccessHandler, 1: RecordingLogger, 2: SessionOAuth2AuthorizedClientRepository} */
function logoutHandler(): array
{
    $logger = new RecordingLogger;
    $clients = new SessionOAuth2AuthorizedClientRepository(app());
    $handler = new OidcClientInitiatedLogoutSuccessHandler(
        new InMemoryClientRegistrationRepository([logoutRegistration('okta', 'https://idp/logout?tenant=a'), logoutRegistration('plain', null)]),
        $clients,
        new OAuth2ClientSettings(postLogoutRedirectUri: '{baseUrl}/bye?from={registrationId}'),
        app(),
        $logger,
    );

    return [$handler, $logger, $clients];
}

function oauth2Principal(string $registrationId): Authentication
{
    return OAuth2AuthenticationToken::of(new DefaultOAuth2User([new SimpleGrantedAuthority('OIDC_USER')], ['sub' => 'ada'], 'sub'), [], $registrationId);
}

it('sends the browser to the end-session endpoint with the id token hint, the client id and the expanded post-logout URI', function () {
    [$handler, $logger, $clients] = logoutHandler();
    $request = logoutRequest();
    $clients->saveAuthorizedClient(new OAuth2AuthorizedClient('okta', 'ada', new OAuth2AccessToken('at', 1, null), null, 'raw.id.token'), $request);

    $response = $handler->onLogoutSuccess($request, oauth2Principal('okta'));

    expect($response?->getStatusCode())->toBe(302)
        ->and($response?->headers->get('Location'))->toBe('https://idp/logout?tenant=a&id_token_hint=raw.id.token&client_id=app-okta&post_logout_redirect_uri=http%3A%2F%2Flocalhost%2Fbye%3Ffrom%3Dokta')
        ->and($logger->records)->toBe([]);

    // No id token in the session (a plain OAuth2 login, or a session that lost it): the hint is left out, the rest stands.
    $clients->removeAuthorizedClient('okta', $request);
    expect($handler->onLogoutSuccess($request, oauth2Principal('okta'))?->headers->get('Location'))->toBe('https://idp/logout?tenant=a&client_id=app-okta&post_logout_redirect_uri=http%3A%2F%2Flocalhost%2Fbye%3Ffrom%3Dokta');
});

it('hands back to the default redirect for a principal that did not sign in through OAuth2, an unknown registration, or a provider without an end-session endpoint', function () {
    [$handler, $logger] = logoutHandler();
    $request = logoutRequest();

    expect($handler->onLogoutSuccess($request, null))->toBeNull()
        ->and($handler->onLogoutSuccess($request, Authentication::authenticated('ada', 'ada', [])))->toBeNull()
        ->and($logger->records)->toBe([])
        ->and($handler->onLogoutSuccess($request, oauth2Principal('gone')))->toBeNull()
        ->and($logger->mentioning('[gone]'))->toHaveCount(1)
        ->and($handler->onLogoutSuccess($request, oauth2Principal('plain')))->toBeNull()
        ->and($logger->mentioning('[plain]'))->toHaveCount(1)
        ->and($logger->records[1]['level'])->toBe('warning')
        ->and($logger->records[1]['message'])->toContain('end_session');
});
