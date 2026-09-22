<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Support;

use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2ServerSchema;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

/**
 * The capstone over the `eloquent` drivers: the same providers, users and clients, with clients, authorizations
 * and consents in the three tables on sqlite :memory: — created here from the shipped schema, exactly what the
 * migration runs. With `clients.driver = eloquent` the bean never reads the config map, so clients() seeds the
 * rows through the repository before each test and every flow runs against them.
 */
abstract class OAuth2ServerEloquentCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function serverOverrides(): array
    {
        return [
            'firefly.security.oauth2.server.clients.driver' => 'eloquent',
            'firefly.security.oauth2.server.authorizations.driver' => 'eloquent',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        OAuth2ServerSchema::create();

        /** @var RegisteredClientRepository $repository */
        $repository = $this->app()->make(RegisteredClientRepository::class);
        /** @var AuthorizationServerSettings $settings */
        $settings = $this->app()->make(AuthorizationServerSettings::class);
        foreach ($this->clients() as $key => $block) {
            /** @var array<string,mixed> $block */
            $repository->save(RegisteredClientFactory::fromConfig((string) $key, $block, $settings));
        }
    }
}
