<?php

declare(strict_types=1);

use Firefly\Data\DataServiceProvider;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentOAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;
use Firefly\Testing\Concerns\UsesSqliteMemory;
use Illuminate\Foundation\Application;

abstract class EloquentDriversBootTestCase extends OAuth2ServerBootTestCase
{
    use UsesSqliteMemory;

    protected function fireflyProviders(): array
    {
        return [DataServiceProvider::class, ...parent::fireflyProviders()];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $this->defineSqliteMemory($app);
    }

    protected function serverOverrides(): array
    {
        return [
            'firefly.security.oauth2.server.clients' => ['driver' => 'eloquent'],
            'firefly.security.oauth2.server.authorizations' => ['driver' => 'eloquent'],
        ];
    }
}

uses(EloquentDriversBootTestCase::class);

it('binds the three Eloquent adapters when both drivers are eloquent, and the migration is registered', function () {
    /** @var EloquentDriversBootTestCase $this */
    expect($this->app()->make(RegisteredClientRepository::class))->toBeInstanceOf(EloquentRegisteredClientRepository::class)
        ->and($this->app()->make(OAuth2AuthorizationService::class))->toBeInstanceOf(EloquentOAuth2AuthorizationService::class)
        ->and($this->app()->make(OAuth2AuthorizationConsentService::class))->toBeInstanceOf(EloquentOAuth2AuthorizationConsentService::class);

    /** @var Application $app */
    $app = $this->app();
    $paths = $app->make('migrator')->paths();
    expect(array_filter($paths, static fn (string $path): bool => str_ends_with($path, 'security-oauth2-server/database/migrations')))->not->toBeEmpty();
});
