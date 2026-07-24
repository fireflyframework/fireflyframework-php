<?php

declare(strict_types=1);

namespace Firefly\Cqrs;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Cqrs\Boot\CqrsHandlerWiringPass;
use Firefly\Cqrs\Boot\DomainEventBridgeWiringPass;
use Firefly\Cqrs\Handler\HandlerManifest;

/**
 * The boot-pass half of firefly/cqrs. It CANNOT ride on CqrsServiceProvider: that extends AutoConfiguration, whose
 * final register() records candidacy ONLY and never consumes passes(). So — exactly like EdaWiringProvider /
 * SchedulingWiringProvider — this plain FireflyServiceProvider contributes the wiring pass(es) via passes() and
 * binds a default empty HandlerManifest behind a bound() guard (a bare skeleton with no compiled manifest still
 * boots; an app that binds its own compiled manifest, or firefly:cache does, wins). Both this and CqrsServiceProvider
 * are listed in extra.laravel.providers. Task 12 adds DomainEventBridgeWiringPass to passes().
 */
final class CqrsWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(HandlerManifest::class)) {
            $this->app->singleton(HandlerManifest::class, static fn (): HandlerManifest => new HandlerManifest([], []));
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
