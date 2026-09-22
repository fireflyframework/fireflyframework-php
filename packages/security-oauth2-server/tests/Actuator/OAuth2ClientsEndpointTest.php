<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Security\OAuth2\Server\Actuator\OAuth2ClientsEndpoint;
use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\InMemoryRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

it('lists every client with its grants, scopes, settings and live authorization count, and never a secret', function () {
    $settings = new AuthorizationServerSettings(issuer: 'https://issuer.test');
    $clients = InMemoryRegisteredClientRepository::fromConfig([
        'web-app' => ['client_secret' => '{noop}very-secret', 'redirect_uris' => ['https://a.test/cb'], 'scopes' => ['openid']],
        'svc' => ['client_secret' => '{noop}s', 'authorization_grant_types' => ['client_credentials']],
    ], $settings);
    $authorizations = new InMemoryOAuth2AuthorizationService;
    $web = $clients->findById('web-app') ?? throw new RuntimeException('no client');
    $authorizations->save(OAuth2Authorization::create($web, 'ada', AuthorizationGrantType::AuthorizationCode, ['openid'])
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AccessToken, 'a', new DateTimeImmutable, new DateTimeImmutable('+5 minutes'))));

    $endpoint = new OAuth2ClientsEndpoint($clients, $authorizations, $settings);
    $body = $endpoint->handle(new EndpointRequest('GET', []))->body;
    // Narrowed row by row rather than with nested offsets: the payload is array<mixed> to PHPStan, so
    // `$body['clients'][0]['activeAuthorizations']` is an offset access on mixed at level max.
    $rows = is_array($body) && is_array($body['clients'] ?? null) ? $body['clients'] : [];
    $web = is_array($rows[0] ?? null) ? $rows[0] : [];
    $svc = is_array($rows[1] ?? null) ? $rows[1] : [];

    expect($endpoint->endpointId())->toBe('oauth2clients')
        ->and($endpoint->enabled())->toBeTrue()
        ->and(is_array($body) ? $body['issuer'] : null)->toBe('https://issuer.test')
        ->and($rows)->toHaveCount(2)
        ->and($web)->toMatchArray([
            'id' => 'web-app', 'clientId' => 'web-app', 'clientName' => 'web-app',
            'authenticationMethods' => ['client_secret_basic'], 'grantTypes' => ['authorization_code', 'refresh_token'],
            'scopes' => ['openid'], 'redirectUris' => ['https://a.test/cb'], 'requireProofKey' => false, 'requireAuthorizationConsent' => true,
            'accessTokenFormat' => 'self_contained', 'accessTokenTtl' => 300, 'activeAuthorizations' => 1,
        ])
        ->and($svc['activeAuthorizations'] ?? null)->toBe(0)
        ->and(json_encode($body))->not->toContain('very-secret');
});
