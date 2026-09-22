<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

/**
 * The `memory` driver: the `firefly.security.oauth2.server.clients` map, built and validated once at boot.
 * `driver` is the one reserved key of that map and is never read as a client. save() replaces by id, so a
 * dynamically registered client or a test's client lives for the process.
 */
final class InMemoryRegisteredClientRepository implements RegisteredClientRepository
{
    /** @var array<string,RegisteredClient> id => client */
    private array $clients = [];

    /**
     * @param  list<RegisteredClient>  $clients
     */
    public function __construct(array $clients = [])
    {
        foreach ($clients as $client) {
            $this->save($client);
        }
    }

    /**
     * @param  array<string,mixed>  $clients
     */
    public static function fromConfig(array $clients, AuthorizationServerSettings $settings): self
    {
        $repository = new self;
        $seen = [];
        foreach ($clients as $key => $block) {
            if ($key === 'driver') {
                continue;
            }
            if (! is_array($block)) {
                throw new ConfigurationException("Client [{$key}]: the block must be a map.");
            }
            /** @var array<string,mixed> $block */
            $client = RegisteredClientFactory::fromConfig((string) $key, $block, $settings);
            if (isset($seen[$client->clientId])) {
                throw new ConfigurationException("Clients [{$seen[$client->clientId]}] and [{$key}] share the client_id [{$client->clientId}].");
            }
            $seen[$client->clientId] = (string) $key;
            $repository->save($client);
        }

        return $repository;
    }

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
