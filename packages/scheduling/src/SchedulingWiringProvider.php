<?php

declare(strict_types=1);

namespace Firefly\Scheduling;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\Scheduling\Boot\ScheduleWiringPass;
use Firefly\Scheduling\Scanner\ScheduledScanner;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Illuminate\Contracts\Container\Container;

/**
 * The boot-pass half of firefly/scheduling. It CANNOT ride on SchedulingServiceProvider: that extends
 * AutoConfiguration, whose final register() records candidacy ONLY and never consumes passes(). So — exactly
 * like WebServiceProvider — this plain FireflyServiceProvider contributes the ScheduleWiringPass via passes()
 * and resolves the ScheduledManifest behind a bound() guard (compiled artifact first, then an in-process scan
 * of firefly.scan.paths, then empty). Both this and SchedulingServiceProvider are listed in
 * extra.laravel.providers.
 */
final class SchedulingWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(ScheduledManifest::class)) {
            $this->app->singleton(ScheduledManifest::class, static function (Container $app): ScheduledManifest {
                if (($file = AppScan::cachedFile($app, AppScan::SCHEDULED)) !== null) {
                    return ScheduledManifest::load($file);
                }

                $paths = AppScan::paths($app);

                return new ScheduledManifest($paths === [] ? [] : (new ScheduledScanner)->scan($paths));
            });
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
