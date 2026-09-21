<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use Firefly\Data\Repository\EloquentRepository;

/**
 * The framework's own repository over the clients table: exception translation, paging, and the admin data
 * browser come with it. Built by the auto-configuration, never scanned.
 *
 * @extends EloquentRepository<RegisteredClientModel>
 *
 * @method RegisteredClientModel|null findFirstByClientId(string $clientId)
 */
final class RegisteredClientModelRepository extends EloquentRepository
{
    protected string $model = RegisteredClientModel::class;
}
