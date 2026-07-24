<?php

declare(strict_types=1);

namespace Firefly\Security;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Boot\SecurityWiringPass;

/**
 * The boot-pass half of firefly/security (cannot ride on SecurityServiceProvider — AutoConfiguration's final
 * register() records candidacy only). Binds a default EMPTY SecurityMethodManifest behind a bound() guard (a bare
 * skeleton with no compiled method-security manifest still boots; an app that binds its compiled manifest, or
 * firefly:cache does, wins), and contributes the SecurityWiringPass. Both this and SecurityServiceProvider are in
 * extra.laravel.providers.
 */
final class SecurityWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(SecurityMethodManifest::class)) {
            $this->app->singleton(SecurityMethodManifest::class, static fn (): SecurityMethodManifest => new SecurityMethodManifest([]));
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new SecurityWiringPass];
    }
}
