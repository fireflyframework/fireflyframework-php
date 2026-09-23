<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\OpenApi;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\OpenApi\Security\SecurityScheme;
use Firefly\OpenApi\Security\SecuritySchemeContributor;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Web\Consent\ConsentPage;

/**
 * The ONE security fact configuration cannot supply, so the ONE that needs a contributor rather than a
 * config read: this application IS an authorization server, its flow URLs are its own endpoints under its
 * own issuer, and the scopes it will actually grant are the union of what its registered clients ask for.
 * None of that is in `firefly.security.*` in a form firefly/openapi could read — the endpoints are
 * AuthorizationServerSettings defaults an application may override, and the clients may live in a database
 * — which is exactly why SecuritySchemeContributor exists.
 *
 * The scheme is a REAL `type: oauth2` with an `authorizationCode` flow, unlike the resource server's
 * http/bearer scheme: this server has an authorization URL and a token URL to publish, so Swagger UI can
 * render an "Authorize" button that completes the flow against it. Scopes are collected only from clients
 * that support the authorization_code grant — a client_credentials-only client's scopes belong to a flow
 * this scheme does not describe — and are sorted, so the document does not reshuffle with the client store's
 * iteration order.
 *
 * AN ENABLED SERVER ALWAYS PUBLISHES THE SCHEME, EVEN WITH NO AUTHORIZATION-CODE CLIENT REGISTERED, and
 * that is the half of this class that keeps the document valid rather than merely complete. The flow URLs
 * are facts about the SERVER — its own endpoints under its own issuer — not about its client registry, and
 * `firefly.security.oauth2.server.enabled` is the very fact
 * Firefly\Security\OpenApi\MethodSecurityRequirementContributor names `oauth2AuthorizationCode` from. An
 * emit-nothing rule would make the two disagree exactly where it costs most: a client_credentials-only
 * token issuer, or an `eloquent` client table that is empty or unreachable when the document is generated
 * in CI, would publish operations naming a scheme `components.securitySchemes` does not contain —
 * SecurityModel::resolve() leaves such a name exactly as written on purpose, so the result is a dangling
 * reference no tooling can resolve and an invalid 3.1 document. The honest answer for a server nobody has
 * registered a code client with is the flow with an EMPTY `scopes` map, which is valid OpenAPI (the
 * generator's objectify() writes the empty map as `{}`) and which says what is true: these are the URLs,
 * and no client has registered a scope for them yet.
 *
 * A refreshUrl is published only when at least one such client also supports refresh_token, because an
 * OpenAPI `refreshUrl` a client cannot use is an invitation to a failed request. It is the TOKEN endpoint,
 * which is where RFC 6749 §6 puts a refresh: this server has no separate URL for it and inventing one would
 * describe an endpoint that answers 404.
 *
 * THE SCHEME CARRIES NO DEFAULT SCOPES (SecurityScheme's third argument, left empty). The `scopes` map in
 * the flow says which scopes EXIST; SecurityScheme::$scopes would say which ones every operation naming this
 * scheme NEEDS. A server whose clients between them registered a dozen scopes would, by setting it, demand
 * all twelve on every path — see SecurityModel::resolve(), the one place that field is read.
 *
 * REGISTERED AS A #[Component], NOT AS A #[Bean], and that is load-bearing rather than stylistic:
 * OpenApiAutoConfiguration::securityModel() collects contributors with `Container::getAll()`, which resolves
 * the container tag `firefly.contract.<interface>`, and that tag is written only while walking the SCANNED
 * ComponentManifest. An object a #[Bean] factory returned under this concrete type would be built and then
 * silently dropped, and the only symptom would be a document quietly missing its oauth2 scheme. The
 * conditions are the HttpSecurityFilter shape: #[ConditionalOnClass] so an application without
 * firefly/openapi (a `suggest` of this package, never a require) never loads a class that implements an
 * absent interface, and the master flag AND the server flag so a switched-off server contributes nothing.
 *
 * THE MASTER FLAG IS NOT DECORATION HERE, it is the rule every bean in OAuth2ServerAutoConfiguration
 * already obeys: this component consumes master-gated beans (the settings, the client store), so a surface
 * flag alone would register a component whose constructor cannot be satisfied — and the eager singleton
 * phase would answer `RegisteredClientRepository is not instantiable` instead of the boot refusal naming
 * `firefly.security.enabled` that OAuth2ServerWiringPass exists to give.
 */
#[Component]
#[ConditionalOnClass(SecuritySchemeContributor::class)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class AuthorizationServerSchemeContributor implements SecuritySchemeContributor
{
    public const string NAME = 'oauth2AuthorizationCode';

    public function __construct(
        private readonly AuthorizationServerSettings $settings,
        private readonly RegisteredClientRepository $clients,
    ) {}

    /**
     * @return list<SecurityScheme>
     */
    public function schemes(): array
    {
        if (! $this->settings->enabled) {
            return [];
        }

        $clients = array_values(array_filter(
            $this->clients->all(),
            static fn (RegisteredClient $client): bool => $client->supportsGrant(AuthorizationGrantType::AuthorizationCode),
        ));

        $flow = [
            'authorizationUrl' => $this->settings->endpointUrl($this->settings->authorizationEndpoint),
            'tokenUrl' => $this->settings->endpointUrl($this->settings->tokenEndpoint),
            'scopes' => $this->scopes($clients),
        ];

        if ($this->anyRefreshes($clients)) {
            $flow['refreshUrl'] = $this->settings->endpointUrl($this->settings->tokenEndpoint);
        }

        return [new SecurityScheme(self::NAME, [
            'type' => 'oauth2',
            'description' => 'This application is the authorization server: '.$this->settings->issuer,
            'flows' => ['authorizationCode' => $flow],
        ])];
    }

    /**
     * The union of what the authorization-code clients registered, sorted, each with the description the
     * CONSENT PAGE would show for it.
     *
     * The OpenAPI scopes map wants a description per scope, this server has one only for the three OIDC
     * scopes it defines itself, and ConsentPage::describe() already holds exactly those three — so it is
     * reused rather than copied. The two places are the same claim to the same person: the consent screen
     * says what a scope lets a client do while they approve it, and Swagger UI's authorize dialog says it
     * while they pick one. A second table would let those two sentences drift, and an application's own
     * vocabulary (`orders.write`) gets the same honest "Access orders.write" in both instead of invented
     * prose in one of them.
     *
     * @param  list<RegisteredClient>  $clients
     * @return array<string, string>
     */
    private function scopes(array $clients): array
    {
        $scopes = [];
        foreach ($clients as $client) {
            foreach ($client->scopes as $scope) {
                if ($scope !== '') {
                    $scopes[$scope] = ConsentPage::describe($scope);
                }
            }
        }
        ksort($scopes);

        return $scopes;
    }

    /** @param list<RegisteredClient> $clients */
    private function anyRefreshes(array $clients): bool
    {
        foreach ($clients as $client) {
            if ($client->supportsGrant(AuthorizationGrantType::RefreshToken)) {
                return true;
            }
        }

        return false;
    }
}
