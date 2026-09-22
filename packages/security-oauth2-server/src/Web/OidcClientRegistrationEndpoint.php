<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use DateTimeImmutable;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST {oidc_client_registration_endpoint} — RFC 7591 / OpenID Connect Dynamic Client Registration (Spring's
 * OidcClientRegistrationEndpointFilter), answered only when the path is configured: a bearer access token with
 * the `client.create` scope (Spring's rule — a client registered with that scope obtains it through client
 * credentials) registers a client from `client_name`, `redirect_uris`, `post_logout_redirect_uris`,
 * `grant_types`, `token_endpoint_auth_method` and `scope`. The ids are random; the secret is returned ONCE,
 * in the 201 body, and stored encoded.
 *
 * THE BEARER IS SINGLE-USE. A successful registration invalidates the access token that paid for it — the same
 * `OAuth2Authorization::withInvalidatedToken()` the revocation endpoint writes — so an initial access token
 * registers ONE client and a second POST carrying it is `invalid_token` (401). That is Spring's rule
 * (OidcClientRegistrationAuthenticationProvider invalidates the authorized access token after registering) and
 * it is what keeps `client.create` from being a licence: without it one bearer would register clients without
 * limit for its whole TTL, and rotating the secret of the client that obtained it would revoke nothing.
 * Registering a client that carries `client.create` ITSELF is refused for the same reason — a client with that
 * scope and the client_credentials grant mints its own registration bearers for ever, and no secret rotation
 * anywhere reaches it. (The invalidation binds every reader that asks the authorization store, which every
 * endpoint of this server does; it does not reach a `self_contained` access token presented to some OTHER
 * resource server, exactly as TokenRevocationEndpoint's docblock qualifies.)
 *
 * THE STORE MUST OUTLIVE THE REQUEST. The registered client is written through RegisteredClientRepository, and
 * InMemoryRegisteredClientRepository rebuilds itself from the config map in every process — a 201 whose
 * credentials nothing could ever authenticate. OAuth2ServerWiringPass refuses that pairing at boot (refusal 6,
 * against the store this application RESOLVES and not the `clients.driver` key), so this endpoint only ever has
 * an address beside a store that outlives the request: the `eloquent` driver, or a durable repository of the
 * application's own.
 *
 * ONE LINE IS LOGGED, at INFO, naming the client that was created, the client whose bearer paid for it, the
 * principal that bearer belonged to and the authorization id that was spent — the same shape TokenRevocationEndpoint
 * and AuthorizationEndpoint log their own events in. This is the most privileged write the server performs and
 * the only record of it otherwise is the oauth2_registered_clients row, which says WHAT was created and never
 * WHO created it: when a `client.create` bearer leaks, that missing fact is the first one an incident response
 * asks for. It is also what makes the single-use rule above observable — an operator can see which authorization
 * a registration spent, instead of trusting that one was.
 *
 * Metadata that would not authenticate or redirect safely is `invalid_client_metadata` / `invalid_redirect_uri`.
 * The rules are RegisteredClientFactory::assertConsistent() — the ONE rule set every store runs, the same one a
 * config block meets at boot and the `eloquent` driver re-runs on every read — applied to the BUILT client, so a
 * rule added there reaches a dynamically registered client instead of becoming a ConfigurationException 500 on
 * the first read of the row this endpoint just wrote. The redirect-URI checks ahead of it are not a second rule
 * set: they are a CLASSIFIER, run first only so those failures carry RFC 7591's `invalid_redirect_uri` code
 * rather than `invalid_client_metadata`.
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
        private readonly ?LoggerInterface $logger = null,
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
            [$authorization, $token] = BearerToken::resolve($value, $this->jwt, $this->authorizations);
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

        if (in_array(self::SCOPE, $scope, true)) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT_METADATA, 'scope must not ask for '.self::SCOPE.': a registered client that can register clients would renew that power for ever. Issue an initial access token instead.'));
        }

        // The CLASSIFIER, not a rule set: assertConsistent() below makes both of these checks again. They run
        // first only so a bad or missing redirect URI answers RFC 7591's `invalid_redirect_uri` code.
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

        try {
            RegisteredClientFactory::assertConsistent($client);
        } catch (ConfigurationException $e) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT_METADATA, $e->getMessage()));
        }

        // The authorizing client by the id it goes by on the wire, as the sibling endpoints log it; the
        // authorization's own `registeredClientId` is the registration's stable id, which is what is left when
        // the registration itself has since been removed.
        $registrar = $this->clients->findById($authorization->registeredClientId);
        $registrarId = $registrar === null ? $authorization->registeredClientId : $registrar->clientId;
        $this->clients->save($client);
        // One bearer, one client: the authorization is saved with its access token invalidated, so this value is
        // `invalid_token` from the next request on.
        $this->authorizations->save($authorization->withInvalidatedToken(OAuth2TokenType::AccessToken));
        $this->logger?->info('OAuth2 client ['.$clientId.'] registered by client ['.$registrarId.'] for ['.$authorization->principalName.'] with authorization ['.$authorization->id.'].');

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
