<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

/** The `memory` consent driver: a per-process map keyed by client and principal. */
final class InMemoryOAuth2AuthorizationConsentService implements OAuth2AuthorizationConsentService
{
    /** @var array<string,OAuth2AuthorizationConsent> */
    private array $consents = [];

    public function save(OAuth2AuthorizationConsent $consent): void
    {
        $this->consents[OAuth2AuthorizationConsent::id($consent->registeredClientId, $consent->principalName)] = $consent;
    }

    public function remove(OAuth2AuthorizationConsent $consent): void
    {
        unset($this->consents[OAuth2AuthorizationConsent::id($consent->registeredClientId, $consent->principalName)]);
    }

    public function findById(string $registeredClientId, string $principalName): ?OAuth2AuthorizationConsent
    {
        return $this->consents[OAuth2AuthorizationConsent::id($registeredClientId, $principalName)] ?? null;
    }
}
