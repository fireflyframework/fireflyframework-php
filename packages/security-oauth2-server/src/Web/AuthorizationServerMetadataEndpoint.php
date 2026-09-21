<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The discovery documents — OpenID Connect Discovery 1.0 at /.well-known/openid-configuration and RFC 8414 at
 * /.well-known/oauth-authorization-server — as one document: the OIDC one is a superset and RFC 8414 allows
 * extra members, so a client that reads either learns every endpoint URL (issuer + path), the grants, the
 * client-authentication methods, `S256` as the only challenge method and the signing algorithm. The two paths
 * are fixed by their specifications; `registration_endpoint` appears only when the endpoint is configured.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class AuthorizationServerMetadataEndpoint implements OAuth2Endpoint
{
    public const string OPENID_CONFIGURATION = '/.well-known/openid-configuration';

    public const string OAUTH_AUTHORIZATION_SERVER = '/.well-known/oauth-authorization-server';

    public function __construct(
        private readonly AuthorizationServerSettings $settings,
        private readonly JwtGenerator $jwt,
    ) {}

    public function methods(): array
    {
        return ['GET'];
    }

    public function answersJson(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        return new JsonResponse($this->document(), 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * @return array<string,mixed>
     */
    public function document(): array
    {
        $s = $this->settings;
        $confidential = array_map(static fn (ClientAuthenticationMethod $m): string => $m->value, ClientAuthenticationMethod::confidential());

        $document = [
            'issuer' => $s->issuer,
            'authorization_endpoint' => $s->endpointUrl($s->authorizationEndpoint),
            'token_endpoint' => $s->endpointUrl($s->tokenEndpoint),
            'token_endpoint_auth_methods_supported' => array_map(static fn (ClientAuthenticationMethod $m): string => $m->value, ClientAuthenticationMethod::cases()),
            'jwks_uri' => $s->endpointUrl($s->jwkSetEndpoint),
            'userinfo_endpoint' => $s->endpointUrl($s->oidcUserInfoEndpoint),
            'end_session_endpoint' => $s->endpointUrl($s->oidcLogoutEndpoint),
            'response_types_supported' => ['code'],
            'grant_types_supported' => array_map(static fn (AuthorizationGrantType $g): string => $g->value, AuthorizationGrantType::cases()),
            'revocation_endpoint' => $s->endpointUrl($s->tokenRevocationEndpoint),
            'revocation_endpoint_auth_methods_supported' => $confidential,
            'introspection_endpoint' => $s->endpointUrl($s->tokenIntrospectionEndpoint),
            'introspection_endpoint_auth_methods_supported' => $confidential,
            'code_challenge_methods_supported' => ['S256'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => [$this->jwt->algorithm()],
            'scopes_supported' => ['openid'],
        ];
        if ($s->hasClientRegistration()) {
            $document['registration_endpoint'] = $s->endpointUrl($s->oidcClientRegistrationEndpoint);
        }

        return $document;
    }
}
