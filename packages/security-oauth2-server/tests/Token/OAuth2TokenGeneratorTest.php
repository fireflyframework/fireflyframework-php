<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\GrantedAuthority;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Token\JwtEncodingContext;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenCustomizer;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenGenerator;
use Illuminate\Config\Repository;

/** @param array<string,mixed> $server the `firefly.security.oauth2.server` block, over the issuer https://issuer.test */
function tokenGenerator(?OAuth2TokenCustomizer $customizer = null, array $server = []): OAuth2TokenGenerator
{
    $settings = AuthorizationServerSettings::fromConfig(new Config(new Repository(['firefly' => ['security' => ['oauth2' => ['server' => ['issuer' => 'https://issuer.test'] + $server]]]])));
    $jwt = new JwtGenerator(new JwtSigningKeys(SigningKey::fromPem(KeyPairGenerator::generate('RS256'), 'RS256', 'k1')));

    return new OAuth2TokenGenerator($jwt, $settings, $customizer);
}

/** @param array<string,mixed> $block */
function generatorClient(array $block = []): RegisteredClient
{
    return RegisteredClientFactory::fromConfig('web-app', $block + ['client_secret' => '{noop}s', 'redirect_uris' => ['https://a.test/cb'], 'scopes' => ['openid', 'profile']], new AuthorizationServerSettings(issuer: 'https://issuer.test'));
}

it('issues a JWT access token with the RFC 9068 claims, a refresh token, and an id token for openid with nonce, auth_time, sid and at_hash', function () {
    $generator = tokenGenerator();
    $client = generatorClient();
    $authorization = OAuth2Authorization::create($client, 'ada', AuthorizationGrantType::AuthorizationCode, ['openid', 'profile'], ['nonce' => 'n-1', 'auth_time' => 1_700_000_000, 'sid' => 'sess-1']);
    $before = time();

    $issued = $generator->issue($authorization, $client, true, Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_USER')]));
    $response = $issued->response;
    $access = $generator->jwt()->decode($response->accessToken);
    $id = $generator->jwt()->decode($response->idToken ?? '');
    /** @var string $jti */
    $jti = $access['jti'];

    expect($response->tokenType)->toBe('Bearer')
        ->and($response->expiresIn)->toBe(300)
        ->and($response->scopes)->toBe(['openid', 'profile'])
        ->and($response->refreshToken)->not->toBeNull()
        ->and(strlen((string) $response->refreshToken))->toBe(43)
        ->and($access)->toMatchArray(['iss' => 'https://issuer.test', 'sub' => 'ada', 'aud' => ['web-app'], 'scope' => 'openid profile', 'client_id' => 'web-app'])
        ->and($access['exp'])->toBeGreaterThanOrEqual($before + 300)
        ->and($access['iat'])->toBeGreaterThanOrEqual($before)
        ->and($access['nbf'])->toBeGreaterThanOrEqual($before)
        ->and(strlen($jti))->toBe(32)
        ->and($id)->toMatchArray(['iss' => 'https://issuer.test', 'sub' => 'ada', 'aud' => ['web-app'], 'azp' => 'web-app', 'nonce' => 'n-1', 'auth_time' => 1_700_000_000, 'sid' => 'sess-1'])
        ->and($id['exp'])->toBeGreaterThanOrEqual($before + 1800)
        ->and($id['at_hash'])->toBe(rtrim(strtr(base64_encode(substr(hash('sha256', $response->accessToken, true), 0, 16)), '+/', '-_'), '='));

    $stored = $issued->authorization;
    expect($stored->token(OAuth2TokenType::AccessToken)?->value)->toBe($response->accessToken)
        ->and($stored->token(OAuth2TokenType::AccessToken)?->metadata['format'] ?? null)->toBe('self_contained')
        ->and($stored->token(OAuth2TokenType::AccessToken)?->metadata['claims'] ?? null)->toMatchArray(['jti' => $jti])
        ->and($stored->token(OAuth2TokenType::RefreshToken)?->expiresAt?->getTimestamp())->toBeGreaterThanOrEqual($before + 3600)
        ->and($stored->token(OAuth2TokenType::IdToken)?->value)->toBe($response->idToken)
        ->and($response->toArray())->toHaveKeys(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope', 'id_token'])
        ->and($response->toArray()['scope'])->toBe('openid profile');
});

it('issues an opaque reference token for a client that asks for that format, no refresh token when not asked, no id token without openid', function () {
    $generator = tokenGenerator();
    $client = generatorClient(['token_settings' => ['access_token_format' => 'reference', 'access_token_ttl' => 60], 'scopes' => ['orders:read']]);
    $authorization = OAuth2Authorization::create($client, 'web-app', AuthorizationGrantType::ClientCredentials, ['orders:read']);

    $issued = $generator->issue($authorization, $client, false);

    expect(substr_count($issued->response->accessToken, '.'))->toBe(0)
        ->and(strlen($issued->response->accessToken))->toBe(43)
        ->and($issued->response->expiresIn)->toBe(60)
        ->and($issued->response->refreshToken)->toBeNull()
        ->and($issued->response->idToken)->toBeNull()
        ->and($issued->response->toArray())->not->toHaveKeys(['refresh_token', 'id_token'])
        ->and($issued->authorization->token(OAuth2TokenType::AccessToken)?->metadata['format'] ?? null)->toBe('reference')
        ->and($issued->authorization->token(OAuth2TokenType::AccessToken)?->metadata['claims'] ?? null)->toMatchArray(['sub' => 'web-app'])
        ->and($issued->authorization->token(OAuth2TokenType::RefreshToken))->toBeNull();
});

it('runs the customizer last, for the access token and the id token, with the principal and the grant in hand', function () {
    $customizer = new class implements OAuth2TokenCustomizer
    {
        public function customize(JwtEncodingContext $context): void
        {
            $roles = array_map(static fn (GrantedAuthority $a): string => $a->getAuthority(), $context->principal?->getAuthorities() ?? []);
            $context->claim('roles', $roles);
            $context->claim('kind', $context->tokenType->value.'/'.$context->authorizationGrantType->value);
            if ($context->tokenType === OAuth2TokenType::AccessToken) {
                $context->removeClaim('nbf');
            }
        }
    };
    $generator = tokenGenerator($customizer);
    $client = generatorClient();
    $authorization = OAuth2Authorization::create($client, 'ada', AuthorizationGrantType::AuthorizationCode, ['openid']);

    $issued = $generator->issue($authorization, $client, false, Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_ADMIN')]));
    $access = $generator->jwt()->decode($issued->response->accessToken);
    $id = $generator->jwt()->decode($issued->response->idToken ?? '');

    expect($access['roles'])->toBe(['ROLE_ADMIN'])
        ->and($access['kind'])->toBe('access_token/authorization_code')
        ->and($access)->not->toHaveKey('nbf')
        ->and($id['kind'])->toBe('id_token/authorization_code')
        ->and($id)->toHaveKey('nbf');
});
