<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Authorized;

/**
 * Where authorized clients live OUTSIDE a request (Spring's OAuth2AuthorizedClientService): keyed by registration
 * id and principal name, so a queued job or a scheduled task can call an API as a person who signed in earlier,
 * and so a client-credentials token is fetched once per process pool rather than once per request. The shipped
 * implementation is the Laravel cache with every entry encrypted; an application may bind its own (a table).
 */
interface OAuth2AuthorizedClientService
{
    public function loadAuthorizedClient(string $clientRegistrationId, string $principalName): ?OAuth2AuthorizedClient;

    public function saveAuthorizedClient(OAuth2AuthorizedClient $authorizedClient): void;

    public function removeAuthorizedClient(string $clientRegistrationId, string $principalName): void;
}
