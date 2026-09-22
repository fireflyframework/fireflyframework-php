<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use Firefly\Data\Repository\EloquentRepository;

/**
 * The repository over oauth2_authorization_consents: the base contract is all the consent port needs, since the
 * row id IS the (client, principal) pair.
 *
 * @extends EloquentRepository<OAuth2AuthorizationConsentModel>
 */
final class OAuth2AuthorizationConsentModelRepository extends EloquentRepository
{
    protected string $model = OAuth2AuthorizationConsentModel::class;
}
