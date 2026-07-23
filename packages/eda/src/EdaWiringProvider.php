<?php

declare(strict_types=1);

namespace Firefly\Eda;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Eda\Boot\EventListenerWiringPass;
use Firefly\Eda\Listener\EventListenerManifest;

/**
 * The boot-pass half of firefly/eda. It CANNOT ride on EdaServiceProvider: that extends AutoConfiguration, whose
 * final register() records candidacy ONLY and never consumes passes(). So — exactly like SchedulingWiringProvider
 * — this plain FireflyServiceProvider contributes the EventListenerWiringPass via passes() and binds a default
 * empty EventListenerManifest behind a bound() guard (a bare skeleton with no compiled manifest still boots; an
 * app that binds its own compiled manifest, or firefly:cache does, wins). Both this and EdaServiceProvider are
 * listed in extra.laravel.providers.
 */
final class EdaWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(EventListenerManifest::class)) {
            $this->app->singleton(EventListenerManifest::class, static fn (): EventListenerManifest => new EventListenerManifest([]));
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new EventListenerWiringPass];
    }
}
