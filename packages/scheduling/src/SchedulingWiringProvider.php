<?php

declare(strict_types=1);

namespace Firefly\Scheduling;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Scheduling\Boot\ScheduleWiringPass;
use Firefly\Scheduling\Schedule\ScheduledManifest;

/**
 * The boot-pass half of firefly/scheduling. It CANNOT ride on SchedulingServiceProvider: that extends
 * AutoConfiguration, whose final register() records candidacy ONLY and never consumes passes(). So — exactly
 * like WebServiceProvider — this plain FireflyServiceProvider contributes the ScheduleWiringPass via passes()
 * and binds a default empty ScheduledManifest behind a bound() guard (a bare skeleton with no compiled manifest
 * still boots; an app that binds its own compiled ScheduledManifest, or firefly:cache does, wins). Both this and
 * SchedulingServiceProvider are listed in extra.laravel.providers.
 */
final class SchedulingWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(ScheduledManifest::class)) {
            $this->app->singleton(ScheduledManifest::class, static fn (): ScheduledManifest => new ScheduledManifest([]));
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new ScheduleWiringPass];
    }
}
