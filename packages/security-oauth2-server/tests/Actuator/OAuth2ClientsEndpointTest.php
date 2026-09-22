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
use Firefly\Security\OAuth2\Server\Eloquent\EloquentOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2AuthorizationModelRepository;
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
            'scopes' => ['openid'], 'redirectUris' => ['https://a.test/cb'],
            // Registered false, effective true: the stock `require_pkce` demands it of every code request.
            'requireProofKey' => false, 'requiresProofKey' => true, 'requireAuthorizationConsent' => true,
            'accessTokenFormat' => 'self_contained', 'accessTokenTtl' => 300, 'activeAuthorizations' => 1,
        ])
        ->and($svc['activeAuthorizations'] ?? null)->toBe(0)
        // The counts are the memory driver's, so the payload says whose they are.
        ->and(is_array($body) ? $body['authorizations'] : null)->toBe(['processLocal' => true])
        ->and(json_encode($body))->not->toContain('very-secret');
});

/**
 * `authorizations.processLocal` of a payload, narrowed for PHPStan at level max.
 */
function oauth2ClientsProcessLocal(OAuth2ClientsEndpoint $endpoint): ?bool
{
    $body = $endpoint->handle(new EndpointRequest('GET', []))->body;
    $authorizations = is_array($body) && is_array($body['authorizations'] ?? null) ? $body['authorizations'] : [];
    $processLocal = $authorizations['processLocal'] ?? null;

    return is_bool($processLocal) ? $processLocal : null;
}

/**
 * The counts mean one thing on `memory` and another on `eloquent`, and the payload has to say which.
 *
 * InMemoryOAuth2AuthorizationService is a map rebuilt in every process, so under php-fpm or Octane the worker
 * rendering this payload has issued no tokens of its own and every `activeAuthorizations` reads 0 while the
 * workers beside it hold hundreds — the same trap HttpExchangesEndpoint publishes `storage`/`processLocal`
 * for. Judged on the RESOLVED service, never on `authorizations.driver`: OAuth2AuthorizationService carries
 * #[ConditionalOnMissingBean], so an application that binds a durable service of its own leaves that key
 * naming nothing, and a driver-keyed flag would warn it about a store it does not use.
 *
 * No clients registered on purpose: the flag is a property of the service, and counting against the Eloquent
 * one would need a database to say something this test is not asking about.
 */
it('publishes whether the authorizations it counted are the rendering process\'s own', function () {
    $settings = new AuthorizationServerSettings(issuer: 'https://issuer.test');
    $clients = InMemoryRegisteredClientRepository::fromConfig([], $settings);

    $memory = new OAuth2ClientsEndpoint($clients, new InMemoryOAuth2AuthorizationService, $settings);
    $durable = new OAuth2ClientsEndpoint($clients, new EloquentOAuth2AuthorizationService(new OAuth2AuthorizationModelRepository), $settings);

    expect(oauth2ClientsProcessLocal($memory))->toBeTrue()
        ->and(oauth2ClientsProcessLocal($durable))->toBeFalse();
});

/**
 * The PKCE pair of every row, keyed by client id: what the client registered and what the endpoints enforce.
 * Narrowed here rather than at each expectation because the payload is `array<mixed>` to PHPStan at level max.
 *
 * @param  array<string,mixed>  $clients
 * @return array<string, array{registered: bool, effective: bool}>
 */
function oauth2ClientsPkce(AuthorizationServerSettings $settings, array $clients): array
{
    $repository = InMemoryRegisteredClientRepository::fromConfig($clients, $settings);
    $endpoint = new OAuth2ClientsEndpoint($repository, new InMemoryOAuth2AuthorizationService, $settings);
    $body = $endpoint->handle(new EndpointRequest('GET', []))->body;
    $rows = is_array($body) && is_array($body['clients'] ?? null) ? $body['clients'] : [];

    $pkce = [];
    foreach ($rows as $row) {
        if (! is_array($row) || ! is_string($row['clientId'] ?? null)) {
            continue;
        }
        $pkce[$row['clientId']] = [
            'registered' => ($row['requireProofKey'] ?? null) === true,
            'effective' => ($row['requiresProofKey'] ?? null) === true,
        ];
    }

    return $pkce;
}

it('reports the PKCE the endpoints enforce beside the switch the client registered', function () {
    $clients = [
        // No switch of its own: only a server-wide rule can demand PKCE of it.
        'web-app' => ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']],
        // Its own switch on: it needs PKCE whatever the server-wide rules say.
        'strict-app' => ['client_secret' => '{noop}s', 'redirect_uris' => ['https://b.test/cb'], 'client_settings' => ['require_pkce' => true]],
        // Public: `none` is its only method, so AuthorizationEndpoint refuses it without a code_challenge
        // even with both server-wide rules off — nothing else protects its code.
        'public-spa' => ['client_authentication_methods' => ['none'], 'authorization_grant_types' => ['authorization_code'], 'redirect_uris' => ['https://c.test/cb']],
    ];

    // A stock installation: require_pkce and require_proof_key_for_public_clients both default to true, so
    // every client needs PKCE while its own switch still reads false — the asymmetry the page must not hide.
    expect(oauth2ClientsPkce(new AuthorizationServerSettings, $clients))->toBe([
        'web-app' => ['registered' => false, 'effective' => true],
        'strict-app' => ['registered' => true, 'effective' => true],
        'public-spa' => ['registered' => false, 'effective' => true],
    ])
        ->and(oauth2ClientsPkce(new AuthorizationServerSettings(requirePkce: false, requireProofKeyForPublicClients: false), $clients))->toBe([
            'web-app' => ['registered' => false, 'effective' => false],
            'strict-app' => ['registered' => true, 'effective' => true],
            'public-spa' => ['registered' => false, 'effective' => true],
        ]);
});
