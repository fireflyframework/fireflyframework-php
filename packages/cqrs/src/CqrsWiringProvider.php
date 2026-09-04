<?php

declare(strict_types=1);

namespace Firefly\Cqrs;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\Cqrs\Boot\CqrsHandlerWiringPass;
use Firefly\Cqrs\Boot\DomainEventBridgeWiringPass;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Illuminate\Contracts\Container\Container;

/**
 * The boot-pass half of firefly/cqrs. It CANNOT ride on CqrsServiceProvider: that extends AutoConfiguration, whose
 * final register() records candidacy ONLY and never consumes passes(). So — exactly like EdaWiringProvider /
 * SchedulingWiringProvider — this plain FireflyServiceProvider contributes the wiring pass(es) via passes() and
 * resolves the HandlerManifest behind a bound() guard. Both this and CqrsServiceProvider are listed in
 * extra.laravel.providers. Task 12 adds DomainEventBridgeWiringPass to passes().
 *
 * The binding resolves its own manifest (compiled artifact first, then an in-process scan of firefly.scan.paths,
 * then empty) rather than binding an unconditional empty default. Previously only firefly/cli's
 * FireflyCacheServiceProvider ever loaded the compiled handlers.php, so an app without that require-dev package
 * — including any app that installed the firefly/firefly metapackage — dispatched every command and query into
 * an empty handler table. The closure is lazy, so a cached app that DOES have firefly/cli still pays nothing:
 * cli's $app->instance() replaces this binding before anything resolves it.
 */
final class CqrsWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(HandlerManifest::class)) {
            $this->app->singleton(HandlerManifest::class, static function (Container $app): HandlerManifest {
                if (($file = AppScan::cachedFile($app, AppScan::HANDLERS)) !== null) {
                    return HandlerManifest::load($file);
                }

                $paths = AppScan::paths($app);
                if ($paths === []) {
                    return new HandlerManifest([], []);
                }

                $scanned = (new HandlerScanner)->scan($paths);

                return new HandlerManifest($scanned['handlers'], $scanned['destinations']);
            });
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new CqrsHandlerWiringPass, new DomainEventBridgeWiringPass];
    }
}
