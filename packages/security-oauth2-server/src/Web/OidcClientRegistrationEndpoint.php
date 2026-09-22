<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use DateTimeImmutable;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Client\ClientSettings;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Client\TokenSettings;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenGenerator;
use Firefly\Security\Password\PasswordEncoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST {oidc_client_registration_endpoint} — RFC 7591 / OpenID Connect Dynamic Client Registration (Spring's
 * OidcClientRegistrationEndpointFilter), answered only when the path is configured: a bearer access token with
 * the `client.create` scope (Spring's rule — a client registered with that scope obtains it through client
 * credentials) registers a client from `client_name`, `redirect_uris`, `post_logout_redirect_uris`,
 * `grant_types`, `token_endpoint_auth_method` and `scope`. The ids are random; the secret is returned ONCE,
 * in the 201 body, and stored encoded. Metadata that would not authenticate or redirect safely is
 * `invalid_client_metadata` / `invalid_redirect_uri` — the same rules a config block meets at boot.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class OidcClientRegistrationEndpoint implements OAuth2Endpoint
{
    public const string SCOPE = 'client.create';

    public function __construct(
        private readonly AuthorizationServerSettings $settings,
        private readonly RegisteredClientRepository $clients,
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly JwtGenerator $jwt,
        private readonly PasswordEncoder $encoder,
    ) {}

    public function methods(): array
    {
        return ['POST'];
    }

    public function answersJson(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        $value = BearerToken::value($request);
        if ($value === null) {
            return BearerToken::challenge('A bearer access token with the client.create scope is required.');
        }
        try {
            [, $token] = BearerToken::resolve($value, $this->jwt, $this->authorizations);
        } catch (OAuth2AuthenticationException $e) {
            return BearerToken::challenge($e->error()->description, $e->status());
        }
        $scopes = is_array($token->metadata['scopes'] ?? null) ? $token->metadata['scopes'] : [];
        if (! in_array(self::SCOPE, $scopes, true)) {
            return BearerToken::challenge('The access token has no client.create scope.', 403, OAuth2ErrorCodes::INSUFFICIENT_SCOPE, self::SCOPE);
        }

        /** @var array<string,mixed> $body */
        $body = $request->isJson() ? $request->json()->all() : $request->input();
        $id = RegisteredClientFactory::generateId();

        try {
            $grants = RegisteredClientFactory::grants($body['grant_types'] ?? ['authorization_code'], $id);
            $method = ClientAuthenticationMethod::tryFrom(is_string($body['token_endpoint_auth_method'] ?? null) ? $body['token_endpoint_auth_method'] : 'client_secret_basic')
                ?? throw new ConfigurationException('token_endpoint_auth_method is not one of client_secret_basic, client_secret_post, private_key_jwt or none.');
            $redirectUris = RegisteredClientFactory::strings($body['redirect_uris'] ?? [], 'redirect_uris', $id);
            $postLogout = RegisteredClientFactory::strings($body['post_logout_redirect_uris'] ?? [], 'post_logout_redirect_uris', $id);
            $scope = self::scopes($body['scope'] ?? null);
        } catch (ConfigurationException $e) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT_METADATA, $e->getMessage()));
        }

        try {
            if (in_array(AuthorizationGrantType::AuthorizationCode, $grants, true) && $redirectUris === []) {
                throw new ConfigurationException('redirect_uris is required for the authorization_code grant.');
            }
            foreach ([...$redirectUris, ...$postLogout] as $uri) {
                RegisteredClientFactory::assertRedirectUri($uri, $id);
            }
        } catch (ConfigurationException $e) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REDIRECT_URI, $e->getMessage()));
        }
        if ($method === ClientAuthenticationMethod::PrivateKeyJwt) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT_METADATA, 'private_key_jwt clients are registered through configuration (a jwk_set is needed).'));
        }

        $now = new DateTimeImmutable;
        $clientId = RegisteredClientFactory::generateId();
        $secret = $method === ClientAuthenticationMethod::None ? null : OAuth2TokenGenerator::opaque();
        $client = new RegisteredClient(
            id: $id,
            clientId: $clientId,
            clientIdIssuedAt: $now,
            clientSecret: $secret === null ? null : $this->encoder->encode($secret),
            clientSecretExpiresAt: null,
            clientName: is_string($body['client_name'] ?? null) && $body['client_name'] !== '' ? $body['client_name'] : $clientId,
            clientAuthenticationMethods: [$method],
            authorizationGrantTypes: $grants,
            redirectUris: $redirectUris,
            postLogoutRedirectUris: $postLogout,
            scopes: $scope,
            clientSettings: ClientSettings::fromArray([], $this->settings->consentRequired, $id),
            tokenSettings: TokenSettings::defaults($this->settings),
        );
        $this->clients->save($client);

        $document = [
            'client_id' => $clientId,
            'client_id_issued_at' => $now->getTimestamp(),
            'client_name' => $client->clientName,
            'redirect_uris' => $redirectUris,
            'post_logout_redirect_uris' => $postLogout,
            'grant_types' => array_map(static fn (AuthorizationGrantType $g): string => $g->value, $grants),
            'token_endpoint_auth_method' => $method->value,
            'scope' => implode(' ', $scope),
        ];
        if ($secret !== null) {
            $document['client_secret'] = $secret;
            $document['client_secret_expires_at'] = 0;
        }

        return new JsonResponse($document, 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * RFC 7591 §2 `scope`: one space-delimited string, the shape the token endpoint reads a scope parameter in.
     *
     * @return list<string>
     */
    private static function scopes(mixed $scope): array
    {
        if (! is_string($scope)) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: [], static fn (string $s): bool => $s !== ''));
    }
}
