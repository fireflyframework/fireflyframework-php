<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

/**
 * Where consents live (Spring's OAuth2AuthorizationConsentService): one record per client and principal, replaced
 * on save. The authorization endpoint reads it to decide whether the consent page is needed and writes the merged
 * scopes after an approval; RP-initiated logout leaves it alone (a consent outlives a session).
 */
interface OAuth2AuthorizationConsentService
{
    public function save(OAuth2AuthorizationConsent $consent): void;

    public function remove(OAuth2AuthorizationConsent $consent): void;

    public function findById(string $registeredClientId, string $principalName): ?OAuth2AuthorizationConsent;
}
