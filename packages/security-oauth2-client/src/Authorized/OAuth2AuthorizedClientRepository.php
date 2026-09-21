<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Authorized;

use Illuminate\Http\Request;

/**
 * Where a signed-in person's authorized clients live for the duration of their session (Spring's
 * OAuth2AuthorizedClientRepository): REQUEST-BOUND, so the login filter stores the tokens it obtained where
 * the RP-initiated logout and the manager (on a request) find them, and the session's own lifetime is theirs.
 * The shipped implementation is the Laravel session with every entry encrypted; an application may bind its own.
 */
interface OAuth2AuthorizedClientRepository
{
    public function loadAuthorizedClient(string $clientRegistrationId, Request $request): ?OAuth2AuthorizedClient;

    public function saveAuthorizedClient(OAuth2AuthorizedClient $authorizedClient, Request $request): void;

    public function removeAuthorizedClient(string $clientRegistrationId, Request $request): void;
}
