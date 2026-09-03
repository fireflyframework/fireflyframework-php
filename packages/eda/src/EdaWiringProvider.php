<?php

declare(strict_types=1);

namespace Firefly\Eda;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\Eda\Boot\EventListenerWiringPass;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Illuminate\Contracts\Container\Container;

/**
 * The boot-pass half of firefly/eda. It CANNOT ride on EdaServiceProvider: that extends AutoConfiguration, whose
 * final register() records candidacy ONLY and never consumes passes(). So — exactly like SchedulingWiringProvider
 * — this plain FireflyServiceProvider contributes the EventListenerWiringPass via passes() and resolves the
 * EventListenerManifest behind a bound() guard. Both this and EdaServiceProvider are listed in
 * extra.laravel.providers.
 *
 * The binding resolves its own manifest (compiled artifact first, then an in-process scan of firefly.scan.paths,
 * then empty). Before this, an app without firefly/cli — a require-dev package absent from the firefly/firefly
 * metapackage — published events into a listener table that was permanently empty, and nothing said so.
 */
final class EdaWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(EventListenerManifest::class)) {
            $this->app->singleton(EventListenerManifest::class, static function (Container $app): EventListenerManifest {
                if (($file = AppScan::cachedFile($app, AppScan::EVENT_LISTENERS)) !== null) {
                    return EventListenerManifest::load($file);
                }

                $paths = AppScan::paths($app);

                return new EventListenerManifest($paths === [] ? [] : (new EventListenerScanner)->scan($paths));
            });
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
