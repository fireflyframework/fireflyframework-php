<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;

/** @param array<string, mixed> $client */
function clientSettings(array $client): OAuth2ClientSettings
{
    return OAuth2ClientSettings::fromConfig(new Config(new Repository(['firefly' => ['security' => ['oauth2' => ['client' => $client]]]])));
}

it('reads every default off, with Spring Boot\'s two paths and the RemoteJwksProvider timeouts', function () {
    $settings = clientSettings([]);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->loginEnabled)->toBeFalse()
        ->and($settings->authorizationEndpointBaseUri)->toBe('/oauth2/authorization')
        ->and($settings->redirectionEndpointBaseUri)->toBe('/login/oauth2/code')
        ->and($settings->defaultSuccessUrl)->toBe('/')
        ->and($settings->alwaysUseDefaultSuccessUrl)->toBeFalse()
        ->and($settings->failureUrl)->toBe('/login?error')
        ->and($settings->clockSkewSeconds)->toBe(60)
        ->and($settings->connectTimeoutSeconds)->toBe(5)
        ->and($settings->timeoutSeconds)->toBe(5)
        ->and($settings->discoveryCacheTtlSeconds)->toBe(3600)
        ->and($settings->discoveryEager)->toBeFalse()
        ->and($settings->jwkSetCacheTtlSeconds)->toBe(3600)
        ->and($settings->authorizedClientCacheTtlSeconds)->toBe(86400)
        ->and($settings->httpMacro)->toBeTrue()
        ->and($settings->oidcLogout)->toBeFalse()
        ->and($settings->postLogoutRedirectUri)->toBe('{baseUrl}/login?logout');
});

it('names the registration a GET under either base path is for, and nothing else', function () {
    $settings = clientSettings(['login' => ['authorization_endpoint_base_uri' => '/auth/start', 'redirection_endpoint_base_uri' => '/auth/back/']]);

    expect($settings->registrationIdOfAuthorizationRequest(Request::create('/auth/start/google', 'GET')))->toBe('google')
        ->and($settings->registrationIdOfAuthorizationRequest(Request::create('/auth/start/my-idp.v2?x=1', 'GET')))->toBe('my-idp.v2')
        ->and($settings->registrationIdOfAuthorizationRequest(Request::create('/auth/start/google', 'POST')))->toBeNull()
        ->and($settings->registrationIdOfAuthorizationRequest(Request::create('/auth/start/google/extra', 'GET')))->toBeNull()
        ->and($settings->registrationIdOfAuthorizationRequest(Request::create('/auth/start', 'GET')))->toBeNull()
        ->and($settings->registrationIdOfAuthorizationRequest(Request::create('/auth/start/', 'GET')))->toBeNull()
        ->and($settings->registrationIdOfAuthorizationRequest(Request::create('/auth/start/..', 'GET')))->toBeNull()
        ->and($settings->registrationIdOfRedirection(Request::create('/auth/back/google?code=1&state=2', 'GET')))->toBe('google')
        ->and($settings->registrationIdOfRedirection(Request::create('/auth/start/google', 'GET')))->toBeNull()
        ->and(clientSettings([])->registrationIdOfRedirection(Request::create('/login/oauth2/code/okta', 'GET')))->toBe('okta');
});
