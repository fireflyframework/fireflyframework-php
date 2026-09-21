<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticator;
use Firefly\Security\OAuth2\Server\Client\ClientSettings;
use Firefly\Security\OAuth2\Server\Client\InMemoryRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\TokenSettings;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\Password\BcryptPasswordEncoder;
use Firefly\Security\Password\DelegatingPasswordEncoder;
use Firefly\Security\Password\NoOpPasswordEncoder;
use Firefly\Security\Tests\Support\RecordingLogger;
use Illuminate\Http\Request;

function clientAuthenticatorSettings(): AuthorizationServerSettings
{
    return new AuthorizationServerSettings(issuer: 'https://issuer.test');
}

function clientAuthenticatorEncoder(): DelegatingPasswordEncoder
{
    return new DelegatingPasswordEncoder('bcrypt', ['bcrypt' => new BcryptPasswordEncoder, 'noop' => new NoOpPasswordEncoder]);
}

/**
 * @param  list<array<string,string>>  $jwtClientKeys  JWKs of `jwt-client`, registered as they are (a key stripped of `alg` or `kid` stays so)
 * @param  list<RegisteredClient>  $handBuilt  clients that bypass the factory, for the rules the authenticator holds on its own
 */
function clientAuthenticator(?SigningKey $jwtClientKey = null, ?RecordingLogger $logger = null, array $jwtClientKeys = [], array $handBuilt = []): ClientAuthenticator
{
    $settings = clientAuthenticatorSettings();
    $clients = [
        'web app' => ['client_secret' => '{noop}web-secret', 'client_authentication_methods' => ['client_secret_basic', 'client_secret_post'], 'redirect_uris' => ['https://a.test/cb']],
        'basic-only' => ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb']],
        'spa' => ['client_authentication_methods' => ['none'], 'authorization_grant_types' => ['authorization_code'], 'redirect_uris' => ['https://a.test/cb']],
    ];
    if ($jwtClientKey !== null) {
        $jwtClientKeys = [$jwtClientKey->jwk, ...$jwtClientKeys];
    }
    if ($jwtClientKeys !== []) {
        $clients['jwt-client'] = ['client_authentication_methods' => ['private_key_jwt'], 'authorization_grant_types' => ['client_credentials'], 'client_settings' => ['jwk_set' => ['keys' => $jwtClientKeys]]];
    }
    $repository = InMemoryRegisteredClientRepository::fromConfig($clients, $settings);
    foreach ($handBuilt as $client) {
        $repository->save($client);
    }

    return new ClientAuthenticator($repository, clientAuthenticatorEncoder(), $settings, $logger);
}

/**
 * A client as a store other than the factory could hand out — the Eloquent driver runs assertConsistent() too,
 * but the authenticator is the LAST gate and holds its own rules: a secret past `client_secret_expires_at`, a
 * private_key_jwt client with no key set.
 *
 * @param  list<ClientAuthenticationMethod>  $methods
 * @param  array<string,mixed>|null  $jwkSet
 */
function handBuiltClient(string $id, array $methods, ?string $secret, ?DateTimeImmutable $secretExpiresAt = null, ?array $jwkSet = null): RegisteredClient
{
    return new RegisteredClient(
        id: $id,
        clientId: $id,
        clientIdIssuedAt: null,
        clientSecret: $secret,
        clientSecretExpiresAt: $secretExpiresAt,
        clientName: $id,
        clientAuthenticationMethods: $methods,
        authorizationGrantTypes: [AuthorizationGrantType::ClientCredentials],
        redirectUris: [],
        postLogoutRedirectUris: [],
        scopes: [],
        clientSettings: new ClientSettings(jwkSet: $jwkSet),
        tokenSettings: TokenSettings::fromArray([], clientAuthenticatorSettings(), $id),
    );
}

/** A `client_assertion` request: the JWT-bearer type and the assertion, in the body. */
function assertionRequest(string $assertion): Request
{
    return tokenRequest(['client_assertion_type' => ClientAuthenticator::JWT_BEARER, 'client_assertion' => $assertion]);
}

/**
 * The RFC 7523 claims of `jwt-client`, each overridable, with `exp` removable by passing null.
 *
 * @param  array<string,mixed>  $claims
 * @return array<string,mixed>
 */
function assertionClaims(array $claims = []): array
{
    return array_filter($claims + ['iss' => 'jwt-client', 'sub' => 'jwt-client', 'aud' => 'https://issuer.test/oauth2/token', 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(8))], static fn (mixed $v): bool => $v !== null);
}

/**
 * @param  array<string,mixed>  $body
 * @param  array<string,string>  $headers
 */
function tokenRequest(array $body = [], array $headers = []): Request
{
    $request = Request::create('/oauth2/token', 'POST', $body);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

/**
 * @return array<string,string>
 */
function basic(string $id, string $secret): array
{
    return ['Authorization' => 'Basic '.base64_encode(rawurlencode($id).':'.rawurlencode($secret))];
}

it('authenticates client_secret_basic with RFC 6749 §2.3.1 form-decoding, and client_secret_post', function () {
    $authenticator = clientAuthenticator();

    $viaBasic = $authenticator->authenticate(tokenRequest([], basic('web app', 'web-secret')), false);
    $viaPost = $authenticator->authenticate(tokenRequest(['client_id' => 'web app', 'client_secret' => 'web-secret']), false);

    expect($viaBasic->client->clientId)->toBe('web app')
        ->and($viaBasic->method)->toBe(ClientAuthenticationMethod::ClientSecretBasic)
        ->and($viaPost->method)->toBe(ClientAuthenticationMethod::ClientSecretPost)
        ->and(ClientAuthenticator::usedBasic(tokenRequest([], basic('web app', 'x'))))->toBeTrue()
        ->and(ClientAuthenticator::challengeHeaders(tokenRequest([], basic('web app', 'x'))))->toBe(['WWW-Authenticate' => 'Basic realm="oauth2"'])
        ->and(ClientAuthenticator::challengeHeaders(tokenRequest(['client_id' => 'x'])))->toBe([]);
});

it('refuses, as invalid_client 401, a wrong secret, an unknown client, a method the client does not allow, and no credentials at all', function (Request $request, string $needle) {
    try {
        clientAuthenticator()->authenticate($request, false);
        throw new LogicException('not refused');
    } catch (OAuth2AuthenticationException $e) {
        expect($e->error()->errorCode)->toBe('invalid_client')
            ->and($e->status())->toBe(401)
            ->and($e->getMessage())->toContain($needle)
            ->and($e->getMessage())->not->toContain('web-secret');
    }
})->with([
    'wrong secret' => [tokenRequest([], basic('web app', 'nope')), 'invalid_client'],
    'unknown client' => [tokenRequest([], basic('ghost', 'nope')), 'invalid_client'],
    'basic-only via post' => [tokenRequest(['client_id' => 'basic-only', 'client_secret' => 's']), 'client_secret_post'],
    'nothing' => [tokenRequest(), 'no client authentication'],
    'public client where public is not allowed' => [tokenRequest(['client_id' => 'spa']), 'public'],
]);

it('refuses two methods in one request as invalid_request', function () {
    expect(fn () => clientAuthenticator()->authenticate(tokenRequest(['client_id' => 'web app', 'client_secret' => 'web-secret'], basic('web app', 'web-secret')), false))
        ->toThrow(OAuth2AuthenticationException::class, 'invalid_request');
});

it('refuses, as invalid_request 400, credentials sent in the request URI — the query string is never read, even beside a valid body or header (RFC 6749 §2.3.1)', function (Request $request, string $parameter) {
    expect(ClientAuthenticator::parameterInUri($request))->toBe($parameter);

    try {
        clientAuthenticator()->authenticate($request, true);
        throw new LogicException('not refused');
    } catch (OAuth2AuthenticationException $e) {
        expect($e->error()->errorCode)->toBe('invalid_request')
            ->and($e->status())->toBe(400)
            ->and($e->getMessage())->toContain('request URI')->toContain("[{$parameter}]")
            ->and($e->getMessage())->not->toContain('web-secret');
    }
})->with([
    'client_id and client_secret in the query, an empty body' => [Request::create('/oauth2/token?client_id=web+app&client_secret=web-secret', 'POST', []), 'client_id'],
    'client_secret alone in the query' => [Request::create('/oauth2/token?client_secret=web-secret', 'POST', ['client_id' => 'web app']), 'client_secret'],
    'a client_assertion in the query, no Basic header' => [Request::create('/oauth2/token?client_assertion_type='.rawurlencode(ClientAuthenticator::JWT_BEARER).'&client_assertion=a.b.c', 'POST', []), 'client_assertion'],
    'a bare client_id in the query where public clients are allowed' => [Request::create('/oauth2/token?client_id=spa', 'POST', []), 'client_id'],
    'client_id in the query beside valid Basic credentials' => [(static function (): Request {
        $request = Request::create('/oauth2/token?client_id=web+app', 'POST', []);
        $request->headers->set('Authorization', basic('web app', 'web-secret')['Authorization']);

        return $request;
    })(), 'client_id'],
]);

it('reads the four parameters from the parsed body only: a query string presents nothing', function () {
    $queryOnly = Request::create('/oauth2/token?client_id=web+app&client_secret=web-secret&client_assertion_type='.rawurlencode(ClientAuthenticator::JWT_BEARER).'&client_assertion=a.b.c', 'POST', []);

    expect(ClientAuthenticator::presented($queryOnly))->toBe([])
        ->and(ClientAuthenticator::presented(tokenRequest(['client_id' => 'web app', 'client_secret' => 'web-secret'])))->toBe([[ClientAuthenticationMethod::ClientSecretPost, 'web app', 'web-secret']])
        ->and(ClientAuthenticator::parameterInUri(tokenRequest(['client_id' => 'web app', 'client_secret' => 'web-secret'])))->toBeNull();
});

it('accepts a public client by client_id alone where public clients are allowed', function () {
    $authentication = clientAuthenticator()->authenticate(tokenRequest(['client_id' => 'spa']), true);

    expect($authentication->method)->toBe(ClientAuthenticationMethod::None)
        ->and($authentication->client->isPublic())->toBeTrue();
});

it('refuses a bare client_id for a client that does not list `none`, even where public clients are allowed — a confidential client is never usable without its secret', function (string $clientId) {
    try {
        clientAuthenticator()->authenticate(tokenRequest(['client_id' => $clientId]), true);
        throw new LogicException('not refused');
    } catch (OAuth2AuthenticationException $e) {
        expect($e->error()->errorCode)->toBe('invalid_client')
            ->and($e->status())->toBe(401)
            ->and($e->getMessage())->toContain("[{$clientId}]");
    }
})->with(['confidential, basic and post' => 'web app', 'confidential, basic only' => 'basic-only', 'unknown' => 'ghost']);

it('refuses a secret past client_secret_expires_at — as invalid_client naming the expiry, on Basic and on post alike — and accepts one that has not expired yet', function () {
    $expired = handBuiltClient('expired', [ClientAuthenticationMethod::ClientSecretBasic, ClientAuthenticationMethod::ClientSecretPost], '{noop}old', new DateTimeImmutable('-1 minute'));
    $current = handBuiltClient('current', [ClientAuthenticationMethod::ClientSecretBasic], '{noop}new', new DateTimeImmutable('+1 hour'));
    $authenticator = clientAuthenticator(handBuilt: [$expired, $current]);

    expect($authenticator->authenticate(tokenRequest([], basic('current', 'new')), false)->client->clientId)->toBe('current');

    foreach ([tokenRequest([], basic('expired', 'old')), tokenRequest(['client_id' => 'expired', 'client_secret' => 'old'])] as $request) {
        try {
            $authenticator->authenticate($request, false);
            throw new LogicException('not refused');
        } catch (OAuth2AuthenticationException $e) {
            expect($e->error()->errorCode)->toBe('invalid_client')
                ->and($e->status())->toBe(401)
                ->and($e->getMessage())->toContain('expired')->toContain('[expired]')
                ->and($e->getMessage())->not->toContain('old');
        }
    }

    // A WRONG secret against an expired client is still the one generic sentence: the expiry is not confirmed to a caller who does not hold the secret.
    expect(fn () => $authenticator->authenticate(tokenRequest([], basic('expired', 'nope')), false))->toThrow(OAuth2AuthenticationException::class, 'Client authentication failed');
});

it('verifies a private_key_jwt assertion against the client\'s jwk_set: iss and sub the client, aud the token endpoint or the issuer', function () {
    $key = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'client-key');
    $logger = new RecordingLogger;
    $authenticator = clientAuthenticator($key, $logger);
    $assert = fn (array $claims): string => JWT::encode(assertionClaims(array_filter($claims, 'is_string', ARRAY_FILTER_USE_KEY)), $key->key, 'RS256', 'client-key');

    $ok = $authenticator->authenticate(assertionRequest($assert([])), false);
    expect($ok->method)->toBe(ClientAuthenticationMethod::PrivateKeyJwt)
        ->and($ok->client->clientId)->toBe('jwt-client');

    $viaIssuer = $authenticator->authenticate(assertionRequest($assert(['aud' => 'https://issuer.test'])), false);
    expect($viaIssuer->client->clientId)->toBe('jwt-client');

    $stranger = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'client-key');
    $forged = JWT::encode(assertionClaims(), $stranger->key, 'RS256', 'client-key');

    expect(fn () => $authenticator->authenticate(assertionRequest($forged), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client')
        ->and(fn () => $authenticator->authenticate(assertionRequest($assert(['aud' => 'https://elsewhere.test'])), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client')
        ->and(fn () => $authenticator->authenticate(assertionRequest($assert(['iss' => 'web app'])), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client')
        ->and(fn () => $authenticator->authenticate(tokenRequest(['client_assertion_type' => 'urn:something:else', 'client_assertion' => $assert([])]), false))->toThrow(OAuth2AuthenticationException::class, 'invalid_client');

    // The forged one is the only php-jwt refusal above, and its line says WHY — and never carries the assertion.
    $lines = $logger->mentioning('jwt-client');
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['message'])->toContain('Signature verification failed')
        ->and($lines[0]['message'])->not->toContain($forged)
        ->and($lines[0]['context'])->toBe([]);
});

it('names, in the INFO line, what php-jwt refused — an unknown kid, an expired assertion, a set with no kid to choose by — and tells the client only that authentication failed', function () {
    $key = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'client-key');
    $second = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'client-key-2');
    $logger = new RecordingLogger;
    $authenticator = clientAuthenticator($key, $logger, [$second->jwk]);

    $cases = [
        '"kid" invalid' => JWT::encode(assertionClaims(), $key->key, 'RS256', 'someone-elses-key'),
        'Expired token' => JWT::encode(assertionClaims(['exp' => time() - 120]), $key->key, 'RS256', 'client-key'),
        '"kid" empty' => JWT::encode(assertionClaims(), $key->key, 'RS256'),
    ];
    foreach ($cases as $needle => $assertion) {
        $logger->reset();
        try {
            $authenticator->authenticate(assertionRequest($assertion), false);
            throw new LogicException("not refused: {$needle}");
        } catch (OAuth2AuthenticationException $e) {
            expect($e->error()->errorCode)->toBe('invalid_client')
                ->and($e->status())->toBe(401)
                ->and($e->getMessage())->toBe('invalid_client: Client authentication failed for [jwt-client].');
        }
        $lines = $logger->mentioning('did not verify');
        expect($lines)->toHaveCount(1, $needle)
            ->and($lines[0]['level'])->toBe('info')
            ->and($lines[0]['message'])->toContain($needle)->toContain('[jwt-client]')
            ->and($lines[0]['message'])->not->toContain($assertion);
    }

    // With both keys named, the second verifies too — the kid picks it.
    expect($authenticator->authenticate(assertionRequest(JWT::encode(assertionClaims(), $second->key, 'RS256', 'client-key-2')), false)->client->clientId)->toBe('jwt-client');

    // Something that is not a JWS names no client at all: refused as an unknown client, and there is no one to log about.
    $logger->reset();
    expect(fn () => $authenticator->authenticate(assertionRequest('not-a-jwt'), false))->toThrow(OAuth2AuthenticationException::class, 'Client authentication failed for [].')
        ->and($logger->records)->toBe([]);
});

it('verifies against a JWK that omits `alg` (RS256 for RSA, ES256 for P-256 — the OPTIONAL member RFC 7517 §4.4 lets a client library drop) and, when the set holds one key, an assertion whatever kid it names or omits', function (string $algorithm) {
    $key = SigningKey::fromPem(KeyPairGenerator::generate($algorithm), $algorithm, 'client-key');
    $jwk = $key->jwk;
    unset($jwk['alg'], $jwk['kid']);
    $authenticator = clientAuthenticator(jwtClientKeys: [$jwk]);

    $withKid = $authenticator->authenticate(assertionRequest(JWT::encode(assertionClaims(), $key->key, $algorithm, 'client-key')), false);
    $withoutKid = $authenticator->authenticate(assertionRequest(JWT::encode(assertionClaims(), $key->key, $algorithm)), false);

    expect($withKid->client->clientSettings->jwkSet)->toBe(['keys' => [$jwk]])
        ->and($withoutKid->method)->toBe(ClientAuthenticationMethod::PrivateKeyJwt);
})->with(['RS256', 'ES256']);

it('refuses an assertion without exp, naming the claim, and a client that lists private_key_jwt but holds no jwk_set', function () {
    $key = SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'client-key');
    $logger = new RecordingLogger;
    $keyless = handBuiltClient('keyless', [ClientAuthenticationMethod::PrivateKeyJwt], null);
    $authenticator = clientAuthenticator($key, $logger, handBuilt: [$keyless]);

    expect(fn () => $authenticator->authenticate(assertionRequest(JWT::encode(assertionClaims(['exp' => null]), $key->key, 'RS256', 'client-key')), false))
        ->toThrow(OAuth2AuthenticationException::class, 'carries no exp claim');

    $forKeyless = JWT::encode(assertionClaims(['iss' => 'keyless', 'sub' => 'keyless']), $key->key, 'RS256', 'client-key');
    expect(fn () => $authenticator->authenticate(assertionRequest($forKeyless), false))
        ->toThrow(OAuth2AuthenticationException::class, 'Client authentication failed for [keyless]')
        ->and($logger->records)->toBe([]);
});
