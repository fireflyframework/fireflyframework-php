<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Actuator;

use DateTimeImmutable;
use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

/**
 * /actuator/oauth2clients: every registered client with the methods, grants, scopes, redirect URIs and
 * settings it was registered with, and the authorizations alive for it in the store THIS process reads — what
 * an operator asks first when a client "cannot get a token". Unexposed by default like every endpoint beyond
 * health and info; the admin dashboard's OAuth2 page reads it in-process. No secret ever enters the payload,
 * encoded or not.
 *
 * The payload is built from RegisteredClient's PUBLIC fields rather than from its jsonSerialize() masked
 * view, because an operator needs the redirect URIs, the post-logout URIs and the token settings that the
 * masked view deliberately leaves out — and because reading the fields one at a time is what keeps the
 * secret out by construction: there is no place in describe() where `clientSecret` is named, so a future
 * field added to the model cannot leak into this payload by accident.
 *
 * PKCE IS REPORTED TWICE, and the pair is the point. `requireProofKey` is the client's own switch, exactly as
 * it was registered; `requiresProofKey` is RegisteredClient::requiresProofKey() — the rule the authorization
 * endpoint and the code grant actually enforce, which also fires when the server-wide `require_pkce` is on
 * (its default), when the client is public and `require_proof_key_for_public_clients` is on (also its
 * default), or when `none` is the client's only method. Publishing only the registered switch would tell an
 * operator on a stock installation that no client needs PKCE while every authorization_code client is being
 * refused for a missing `code_challenge` — the first thing this endpoint exists to answer. The dashboard
 * renders the effective field; the registered one stays in the payload because "this client demands PKCE even
 * if you turn the server-wide rule off" is a different fact, and only the pair tells them apart.
 *
 * BOTH PKCE FIELDS AND `requireAuthorizationConsent` DESCRIBE THE AUTHORIZATION-CODE PATH AND ONLY IT, so a
 * client that cannot take that path publishes `null` for all three rather than a switch's default. Nothing
 * else in the server reads them: `requiresProofKey()` is consulted by AuthorizationEndpoint and by
 * AuthorizationCodeGrant, `requireAuthorizationConsent` by AuthorizationEndpoint alone. Yet
 * requiresProofKey() does not look at the client's grants, and `require_pkce` and `consent.required` both
 * default to on — so a client registered for `client_credentials` only, which never sends a `code_challenge`
 * and never reaches a consent screen, would otherwise be published as requiring both and the dashboard would
 * print `· PKCE · consent` beside it. Two rules the operator can then spend an afternoon checking, on the very
 * page opened to explain why a client cannot get a token. `null` says "this does not apply to this client",
 * which is the answer; the keys stay present rather than being omitted so every row keeps one shape.
 *
 * `authorizations.processLocal` IS PUBLISHED BESIDE THE COUNTS, for the reason HttpExchangesEndpoint publishes
 * its own `storage`/`processLocal` pair: a number that is silently this worker's alone sends an operator
 * chasing a bug that does not exist. `authorizations.driver` defaults to `memory`, which resolves to
 * InMemoryOAuth2AuthorizationService — a map rebuilt in every process — so under php-fpm, Herd, Valet or
 * Octane the request rendering this payload has issued no tokens of its own and `activeAuthorizations` is 0
 * for every client, while the workers beside it hold hundreds. Read as "this client has no live
 * authorizations", that 0 is the opposite of the truth, and it is read exactly when a client "cannot get a
 * token" — the question this endpoint exists to answer. So the payload states its own storage model:
 * `"authorizations": {"processLocal": true}` means the counts describe the rendering worker and nothing else,
 * and `authorizations.driver: eloquent` (the oauth2_authorizations table, shared by every worker) is what
 * makes them describe the deployment.
 *
 * THE FLAG IS THE STORE'S OWN ANSWER, NOT THIS ENDPOINT'S GUESS: it is
 * OAuth2AuthorizationService::processLocal(), declared on the port exactly as HttpExchangeRecorder declares
 * `storage()`/`processLocal()`, and printed here verbatim the way HttpExchangesEndpoint prints what its
 * recorder says. The rule OAuth2ServerWiringPass refusal (6) states for the client store holds here too —
 * `oauth2AuthorizationService()` carries #[ConditionalOnMissingBean], so an application may bind a service of
 * its own and `authorizations.driver` then names nothing — and a test on the CONCRETE CLASS fails that
 * extension point in both directions: a durable Redis store is not the memory class and would be classified
 * right only by luck, while an APCu or static-map store, a test double, or a decorator wrapping the memory one
 * is process-local WITHOUT being that class and would be published as durable. The dashboard would then print
 * `0` in the Active column with no caveat at all — the misreading this pair exists to prevent.
 *
 * `handle()` narrows the contract's `?EndpointResponse` to the non-nullable type (the covariant narrowing
 * BeansEndpoint/InfoEndpoint/HttpExchangesEndpoint use): there is no sub-resource to 404 on, so a body is
 * always produced and PHPStan at level max flags the nullable type as dead code otherwise.
 *
 * Both gates, like every component of this package: the endpoint consumes beans that only exist when
 * `firefly.security.enabled` AND `firefly.security.oauth2.server.enabled` are on.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class OAuth2ClientsEndpoint implements ActuatorEndpoint
{
    public function __construct(
        private readonly RegisteredClientRepository $clients,
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly AuthorizationServerSettings $settings,
    ) {}

    public function endpointId(): string
    {
        return 'oauth2clients';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        // One clock for the whole sweep: two clients counted against two different "now"s would report
        // counts that never existed together.
        $now = new DateTimeImmutable;
        $clients = [];
        foreach ($this->clients->all() as $client) {
            $clients[] = $this->describe($client, $now);
        }

        return EndpointResponse::json([
            'issuer' => $this->settings->issuer,
            'authorizations' => ['processLocal' => $this->authorizations->processLocal()],
            'clients' => $clients,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function describe(RegisteredClient $client, DateTimeImmutable $now): array
    {
        // PKCE and consent are enforced on the authorization-code path and nowhere else, so they are reported
        // for a client that can take it and published as `null` — does not apply — for one that cannot.
        $code = $client->supportsGrant(AuthorizationGrantType::AuthorizationCode);

        return [
            'id' => $client->id,
            'clientId' => $client->clientId,
            'clientName' => $client->clientName,
            'authenticationMethods' => array_map(static fn (ClientAuthenticationMethod $m): string => $m->value, $client->clientAuthenticationMethods),
            'grantTypes' => array_map(static fn (AuthorizationGrantType $g): string => $g->value, $client->authorizationGrantTypes),
            'scopes' => $client->scopes,
            'redirectUris' => $client->redirectUris,
            'postLogoutRedirectUris' => $client->postLogoutRedirectUris,
            'requireProofKey' => $code ? $client->clientSettings->requireProofKey : null,
            'requiresProofKey' => $code ? $client->requiresProofKey($this->settings) : null,
            'requireAuthorizationConsent' => $code ? $client->clientSettings->requireAuthorizationConsent : null,
            'accessTokenFormat' => $client->tokenSettings->accessTokenFormat->value,
            'accessTokenTtl' => $client->tokenSettings->accessTokenTtl,
            // Counted in whatever store this process resolved, which on the `memory` driver is the rendering
            // worker's own map — hence the payload's `authorizations.processLocal`, which says so rather than
            // letting a 0 read as "no one holds a token for this client".
            'activeAuthorizations' => $this->authorizations->countActiveForClient($client->id, $now),
        ];
    }
}
