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
 * settings it was registered with and the number of authorizations alive for it right now — what an operator
 * asks first when a client "cannot get a token". Unexposed by default like every endpoint beyond health and
 * info; the admin dashboard's OAuth2 page reads it in-process. No secret ever enters the payload, encoded or
 * not.
 *
 * The payload is built from RegisteredClient's PUBLIC fields rather than from its jsonSerialize() masked
 * view, because an operator needs the redirect URIs, the post-logout URIs and the token settings that the
 * masked view deliberately leaves out — and because reading the fields one at a time is what keeps the
 * secret out by construction: there is no place in describe() where `clientSecret` is named, so a future
 * field added to the model cannot leak into this payload by accident.
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

        return EndpointResponse::json(['issuer' => $this->settings->issuer, 'clients' => $clients]);
    }

    /**
     * @return array<string,mixed>
     */
    private function describe(RegisteredClient $client, DateTimeImmutable $now): array
    {
        return [
            'id' => $client->id,
            'clientId' => $client->clientId,
            'clientName' => $client->clientName,
            'authenticationMethods' => array_map(static fn (ClientAuthenticationMethod $m): string => $m->value, $client->clientAuthenticationMethods),
            'grantTypes' => array_map(static fn (AuthorizationGrantType $g): string => $g->value, $client->authorizationGrantTypes),
            'scopes' => $client->scopes,
            'redirectUris' => $client->redirectUris,
            'postLogoutRedirectUris' => $client->postLogoutRedirectUris,
            'requireProofKey' => $client->clientSettings->requireProofKey,
            'requireAuthorizationConsent' => $client->clientSettings->requireAuthorizationConsent,
            'accessTokenFormat' => $client->tokenSettings->accessTokenFormat->value,
            'accessTokenTtl' => $client->tokenSettings->accessTokenTtl,
            'activeAuthorizations' => $this->authorizations->countActiveForClient($client->id, $now),
        ];
    }
}
