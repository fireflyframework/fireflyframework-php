<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Fixtures\OwnClientStore;

use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;

/**
 * An application's OWN client store — the extension point `registeredClientRepository()`'s
 * #[ConditionalOnMissingBean] exists for (the bean's docblock offers Redis or an internal API). It stands in
 * here for anything durable that is neither the config map nor the oauth2_registered_clients table: what it
 * keeps is beside the point, that it is NOT InMemoryRegisteredClientRepository is the whole point, because that
 * is what boot refusal (6) tests for.
 */
final class OwnRegisteredClientRepository implements RegisteredClientRepository
{
    /** @var array<string,RegisteredClient> */
    private array $clients = [];

    public function findById(string $id): ?RegisteredClient
    {
        return $this->clients[$id] ?? null;
    }

    public function findByClientId(string $clientId): ?RegisteredClient
    {
        foreach ($this->clients as $client) {
            if ($client->clientId === $clientId) {
                return $client;
            }
        }

        return null;
    }

    public function save(RegisteredClient $client): void
    {
        $this->clients[$client->id] = $client;
    }

    public function all(): array
    {
        return array_values($this->clients);
    }
}
