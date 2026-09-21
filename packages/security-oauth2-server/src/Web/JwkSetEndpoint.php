<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET {jwk_set_endpoint}: the public keys (current first, previous after) every resource server verifies with —
 * the same bytes AuthorizationServerJwksDocumentSource hands the in-process filter. Cacheable for an hour: a
 * rotation keeps the old key published, so a stale copy still verifies what it should.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class JwkSetEndpoint implements OAuth2Endpoint
{
    public function __construct(private readonly JwtSigningKeys $keys) {}

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
        return new JsonResponse($this->keys->jwks(), 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
