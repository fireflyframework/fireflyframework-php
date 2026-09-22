<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsent;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsentService;

/** The `eloquent` consent driver: OAuth2AuthorizationConsent ↔ oauth2_authorization_consents (id = client|principal). */
final class EloquentOAuth2AuthorizationConsentService implements OAuth2AuthorizationConsentService
{
    public function __construct(private readonly OAuth2AuthorizationConsentModelRepository $models) {}

    public function save(OAuth2AuthorizationConsent $consent): void
    {
        $id = OAuth2AuthorizationConsent::id($consent->registeredClientId, $consent->principalName);
        $existing = $this->models->findById($id);
        $model = $existing instanceof OAuth2AuthorizationConsentModel ? $existing : new OAuth2AuthorizationConsentModel;
        $model->fill([
            'id' => $id,
            'registered_client_id' => $consent->registeredClientId,
            'principal_name' => $consent->principalName,
            'scopes' => implode(' ', $consent->scopes),
        ]);
        $this->models->save($model);
    }

    public function remove(OAuth2AuthorizationConsent $consent): void
    {
        $this->models->deleteById(OAuth2AuthorizationConsent::id($consent->registeredClientId, $consent->principalName));
    }

    public function findById(string $registeredClientId, string $principalName): ?OAuth2AuthorizationConsent
    {
        $row = $this->models->findById(OAuth2AuthorizationConsent::id($registeredClientId, $principalName));
        if (! $row instanceof OAuth2AuthorizationConsentModel) {
            return null;
        }
        $scopes = $row->getAttribute('scopes');
        $scopes = is_string($scopes) ? $scopes : '';

        return new OAuth2AuthorizationConsent(
            $registeredClientId,
            $principalName,
            $scopes === '' ? [] : array_values(array_filter(explode(' ', $scopes), static fn (string $s): bool => $s !== '')),
        );
    }
}
