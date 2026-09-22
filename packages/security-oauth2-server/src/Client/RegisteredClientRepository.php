<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

/**
 * Where registered clients live (Spring's RegisteredClientRepository): the config map (`memory`) or the
 * oauth2_registered_clients table (`eloquent`). `save()` is what dynamic registration and tests use.
 */
interface RegisteredClientRepository
{
    public function findById(string $id): ?RegisteredClient;

    public function findByClientId(string $clientId): ?RegisteredClient;

    public function save(RegisteredClient $client): void;

    /**
     * @return list<RegisteredClient>
     */
    public function all(): array;
}
