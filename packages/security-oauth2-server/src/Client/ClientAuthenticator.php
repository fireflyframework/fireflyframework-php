<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Jose\ClientJwkSet;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\Password\PasswordEncoder;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Client authentication at the token, introspection and revocation endpoints (Spring's
 * OAuth2ClientAuthenticationFilter with its four providers): exactly ONE of `client_secret_basic` (RFC 6749
 * §2.3.1 — the id and secret are form-urlencoded inside the Basic credentials, so they are decoded here, which
 * Symfony's PHP_AUTH_USER does not do), `client_secret_post`, `private_key_jwt` (RFC 7523: a JWT signed with a
 * key from the client's `jwk_set`, `iss` = `sub` = the client id, `aud` the token endpoint or the issuer, `exp`
 * present) or `none` (a public client, `client_id` alone, only where $allowPublic — the token endpoint).
 *
 * The four parameters are read from the PARSED BODY only, never from the query string: RFC 6749 §2.3.1 says
 * they MUST NOT be in the request URI, because a URI ends up in access logs, proxies and Referer headers, and
 * Laravel's Request::input() would quietly merge the two. One of them in the query string is `invalid_request`
 * (400) naming the parameter, so a client that put its secret in the URL is told rather than left with a 401.
 * Two methods in one request are `invalid_request` too (RFC 6749 §2.3: MUST NOT); everything else that fails is
 * `invalid_client` (401, and the token endpoint adds the Basic challenge when Basic was tried). An UNKNOWN client
 * id costs what a wrong secret costs: the presented secret is still matched against a dummy hash (encoded once,
 * on first use), so timing does not say which. No description ever carries the secret.
 */
final class ClientAuthenticator
{
    public const string REALM = 'oauth2';

    public const string JWT_BEARER = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /**
     * What a client sends to say who it is; body parameters, every one of them (RFC 6749 §2.3.1, RFC 7523 §2.2).
     *
     * @var list<string>
     */
    public const array PARAMETERS = ['client_id', 'client_secret', 'client_assertion', 'client_assertion_type'];

    /** Encoded on first use, not at boot: a bcrypt encode per boot would tax every test and console command. */
    private ?string $dummySecret = null;

    public function __construct(
        private readonly RegisteredClientRepository $clients,
        private readonly PasswordEncoder $encoder,
        private readonly AuthorizationServerSettings $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @throws OAuth2AuthenticationException
     */
    public function authenticate(Request $request, bool $allowPublic): ClientAuthentication
    {
        $inUri = self::parameterInUri($request);
        if ($inUri !== null) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, "Client credentials belong in the request body, never in the request URI (RFC 6749 §2.3.1): [{$inUri}] was sent as a query parameter."), 400);
        }

        $presented = self::presented($request);
        if (count($presented) > 1) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'The request uses more than one client authentication method.'), 400);
        }
        if ($presented === []) {
            throw $this->refuse('The request carries no client authentication.');
        }

        [$method, $clientId, $credential] = $presented[0];
        $client = $this->clients->findByClientId($clientId);

        $verified = match ($method) {
            ClientAuthenticationMethod::ClientSecretBasic, ClientAuthenticationMethod::ClientSecretPost => $this->withSecret($client, $clientId, $method, (string) $credential),
            ClientAuthenticationMethod::PrivateKeyJwt => $this->withAssertion($client, $clientId, (string) $credential),
            ClientAuthenticationMethod::None => $this->asPublic($client, $clientId, $allowPublic),
        };

        return new ClientAuthentication($verified, $method);
    }

    /**
     * `client_secret_basic` / `client_secret_post`: the secret is matched FIRST, against the client's encoded
     * secret or the dummy when there is no such client, so an unknown id and a wrong secret take the same time
     * and share one sentence; only then is the method the client allows and the secret's expiry examined.
     */
    private function withSecret(?RegisteredClient $client, string $clientId, ClientAuthenticationMethod $method, string $secret): RegisteredClient
    {
        $encoded = $client->clientSecret ?? $this->dummySecret();
        $matches = $this->encoder->matches($secret, $encoded);
        if ($client === null || $client->clientSecret === null || ! $matches) {
            throw $this->refuse("Client authentication failed for [{$clientId}].");
        }
        if (! $client->supportsAuthenticationMethod($method)) {
            throw $this->refuse("Client [{$clientId}] may not authenticate with {$method->value}.");
        }
        if ($client->isSecretExpired(new DateTimeImmutable)) {
            throw $this->refuse("The secret of client [{$clientId}] has expired.");
        }

        return $client;
    }

    /**
     * `private_key_jwt`: the client must list the method and hold a JWK set (RegisteredClientFactory::assertConsistent()
     * refuses a client that lists it without one, but a client built by hand is held to the same rule here), and
     * the assertion must verify against that set.
     */
    private function withAssertion(?RegisteredClient $client, string $clientId, string $assertion): RegisteredClient
    {
        if ($client === null || ! $client->supportsAuthenticationMethod(ClientAuthenticationMethod::PrivateKeyJwt) || $client->clientSettings->jwkSet === null) {
            throw $this->refuse("Client authentication failed for [{$clientId}].");
        }
        $this->verifyAssertion($assertion, $client);

        return $client;
    }

    /** `none`: a bare client_id, accepted only where public clients are (the token endpoint) and only for a client that lists `none`. */
    private function asPublic(?RegisteredClient $client, string $clientId, bool $allowPublic): RegisteredClient
    {
        if (! $allowPublic) {
            throw $this->refuse('This endpoint does not accept public clients; authenticate with a client secret.');
        }
        if ($client === null || ! $client->supportsAuthenticationMethod(ClientAuthenticationMethod::None)) {
            throw $this->refuse("Client authentication failed for [{$clientId}].");
        }

        return $client;
    }

    private function dummySecret(): string
    {
        return $this->dummySecret ??= $this->encoder->encode(bin2hex(random_bytes(16)));
    }

    /**
     * Every method the request presents, in a fixed order: Basic, a JWT assertion, a posted secret, a bare id. The
     * parameters are read with Request::post() — the parsed body and nothing else; the query string is refused by
     * authenticate() before this is consulted, and would be ignored here in any case.
     *
     * @return list<array{0: ClientAuthenticationMethod, 1: string, 2: string|null}>
     */
    public static function presented(Request $request): array
    {
        $presented = [];

        $header = $request->header('Authorization');
        if (is_string($header) && str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            $parts = $decoded === false ? [] : explode(':', $decoded, 2);
            if (count($parts) === 2) {
                $presented[] = [ClientAuthenticationMethod::ClientSecretBasic, rawurldecode($parts[0]), rawurldecode($parts[1])];
            }
        }

        $assertionType = $request->post('client_assertion_type');
        $assertion = $request->post('client_assertion');
        if (is_string($assertionType) && is_string($assertion) && $assertion !== '') {
            // The issuer of the assertion is the client; read UNVERIFIED only to know whose keys verify it.
            $presented[] = [ClientAuthenticationMethod::PrivateKeyJwt, $assertionType === self::JWT_BEARER ? self::unverifiedSubject($assertion) : '', $assertion];
        }

        $clientId = $request->post('client_id');
        $secret = $request->post('client_secret');
        if (is_string($secret) && $secret !== '') {
            $presented[] = [ClientAuthenticationMethod::ClientSecretPost, is_string($clientId) ? $clientId : '', $secret];
        } elseif (is_string($clientId) && $clientId !== '' && $presented === []) {
            $presented[] = [ClientAuthenticationMethod::None, $clientId, null];
        }

        return $presented;
    }

    /** The first of PARAMETERS the request carries in its query string, or null when it carries none there. */
    public static function parameterInUri(Request $request): ?string
    {
        foreach (self::PARAMETERS as $name) {
            if ($request->query->has($name)) {
                return $name;
            }
        }

        return null;
    }

    public static function usedBasic(Request $request): bool
    {
        $header = $request->header('Authorization');

        return is_string($header) && str_starts_with($header, 'Basic ');
    }

    /**
     * @return array<string,string>
     */
    public static function challengeHeaders(Request $request): array
    {
        return self::usedBasic($request) ? ['WWW-Authenticate' => 'Basic realm="'.self::REALM.'"'] : [];
    }

    /**
     * The set is parsed by ClientJwkSet (a key without `alg` verifies the algorithm its type implies). A set of
     * several keys is looked up by the header's `kid` — each key has one, assertConsistent() saw to it — and an
     * assertion that names none is refused; a ONE-key set has nothing to pick, so it is used whatever the header
     * says or omits: the kid is a lookup hint, the signature is the proof. What php-jwt refuses is logged at INFO
     * with ITS sentence beside the class ('Signature verification failed', 'Expired token', '"kid" invalid,
     * unable to lookup correct key' — never the token or a key), so an operator can tell a wrong key from a stale
     * clock; the client is told only that authentication failed. The claims are then held to RFC 7523 §3: `iss`
     * and `sub` the client, `aud` the token endpoint or the issuer, `exp` present (php-jwt has already refused
     * one in the past).
     */
    private function verifyAssertion(string $assertion, RegisteredClient $client): void
    {
        try {
            $keys = ClientJwkSet::parse($client->clientSettings->jwkSet ?? ['keys' => []]);
            /** @var array<string,mixed> $claims */
            $claims = (array) JWT::decode($assertion, count($keys) === 1 ? array_values($keys)[0] : $keys);
        } catch (Throwable $e) {
            $this->logger?->info("private_key_jwt assertion of client [{$client->clientId}] did not verify: ".$e::class.' — '.$e->getMessage());
            throw $this->refuse("Client authentication failed for [{$client->clientId}].");
        }

        if (! isset($claims['exp'])) {
            throw $this->refuse("The client assertion of [{$client->clientId}] carries no exp claim.");
        }

        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        $accepted = [$this->settings->endpointUrl($this->settings->tokenEndpoint), $this->settings->issuer];
        $audienceOk = array_intersect(array_filter($audiences, 'is_string'), $accepted) !== [];

        if (($claims['iss'] ?? null) !== $client->clientId || ($claims['sub'] ?? null) !== $client->clientId || ! $audienceOk) {
            throw $this->refuse("The client assertion of [{$client->clientId}] names the wrong issuer, subject or audience.");
        }
    }

    private static function unverifiedSubject(string $assertion): string
    {
        $parts = explode('.', $assertion);
        if (count($parts) !== 3) {
            return '';
        }
        $payload = json_decode(JWT::urlsafeB64Decode($parts[1]), true);

        return is_array($payload) && is_string($payload['sub'] ?? null) ? $payload['sub'] : '';
    }

    private function refuse(string $description): OAuth2AuthenticationException
    {
        return new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT, $description), 401);
    }
}
