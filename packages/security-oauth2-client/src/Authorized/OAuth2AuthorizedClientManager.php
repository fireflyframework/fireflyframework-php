<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Authorized;

use Firefly\Security\OAuth2\Client\Token\ClientAuthorizationRequiredException;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthorizationException;

/**
 * Hands out an authorized client for a registration, obtaining or renewing its tokens as needed (Spring's
 * OAuth2AuthorizedClientManager): the one call application code and the Http macro make before an outbound
 * request. $principalName names whose client is wanted; null means the signed-in person for a user-bound
 * registration and the application itself for a client-credentials one.
 */
interface OAuth2AuthorizedClientManager
{
    /**
     * @throws ClientAuthorizationRequiredException a user-bound registration nobody authorized, or one whose tokens cannot be renewed (401)
     * @throws OAuth2AuthorizationException the provider refused or failed a token request (503)
     */
    public function authorize(string $clientRegistrationId, ?string $principalName = null): OAuth2AuthorizedClient;
}
