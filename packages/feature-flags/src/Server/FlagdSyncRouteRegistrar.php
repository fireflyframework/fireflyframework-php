<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Server;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;

/** Mounts the configured sync path only for applications with their own registry. */
final class FlagdSyncRouteRegistrar implements BootPass
{
    public const string ROUTE_NAME = 'firefly.feature-flags.sync';

    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 55;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(FeatureFlagsSettings::class) || ! $container->bound(FlagRegistry::class)) {
            return;
        }

        /** @var FeatureFlagsSettings $settings */
        $settings = $container->make(FeatureFlagsSettings::class);
        if (! $settings->server->enabled) {
            return;
        }

        /** @var Router $router */
        $router = $container->make('router');
        $router->get($settings->server->path, static fn (Request $request): Response => $container->make(FlagdSyncController::class)($request))
            ->name(self::ROUTE_NAME);
    }
}
