<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Security\OAuth2\Server\Boot\OAuth2AuthorizationPurgeSchedulePass;
use Firefly\Security\OAuth2\Server\Boot\OAuth2ServerEventListenersPass;
use Firefly\Security\OAuth2\Server\Boot\OAuth2ServerWiringPass;

/**
 * The boot-pass half of firefly/security-oauth2-server (cannot ride on SecurityOAuth2ServerServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Contributes OAuth2ServerWiringPass (order 210,
 * after SecurityWiringPass's 200: the server's refusals assume the core's guards already ran),
 * OAuth2ServerEventListenersPass (220: the package's #[AsEventListener] methods, which the phase-800 sweep cannot
 * see in a package manifest) and OAuth2AuthorizationPurgeSchedulePass (InfrastructureStart, 10: the purge
 * descriptor joins the ScheduledManifest before the eager singletons capture it), and boot() loads and publishes
 * the migration of the `eloquent` drivers. Both this and SecurityOAuth2ServerServiceProvider are in
 * extra.laravel.providers.
 */
final class SecurityOAuth2ServerWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new OAuth2ServerWiringPass, new OAuth2ServerEventListenersPass, new OAuth2AuthorizationPurgeSchedulePass];
    }

    /**
     * The migration for the `eloquent` drivers: loaded so `php artisan migrate` runs it, and publishable
     * (`php artisan vendor:publish --tag=firefly-oauth2-server-migrations`) for an application that wants to
     * edit it. Registered whether or not the drivers are on — a migration that exists and creates nothing you
     * use is harmless; one that is missing when you switch drivers is a 500 on the first token request.
     */
    public function boot(): void
    {
        $migrations = dirname(__DIR__).'/database/migrations';
        $this->loadMigrationsFrom($migrations);
        $this->publishes([
            $migrations.'/2026_09_21_000000_create_firefly_oauth2_server_tables.php' => $this->app->databasePath('migrations/2026_09_21_000000_create_firefly_oauth2_server_tables.php'),
        ], 'firefly-oauth2-server-migrations');
    }
}
