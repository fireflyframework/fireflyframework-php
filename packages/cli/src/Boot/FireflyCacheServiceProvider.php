<?php

declare(strict_types=1);

namespace Firefly\Cli\Boot;

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Config\Config;
use Firefly\Config\Registrar\ConfigRegistrar;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Boots the compiled cache: when firefly:cache has emitted artifacts into the app cache dir, binds the
 * Category-B wiring manifests ($app->instance over each *WiringProvider's bound()-guarded empty default),
 * binds the #[ConfigProperties] DTOs, and registers the #[Transactional] proxy autoloader. A pure no-op when
 * the app is uncached (dev), so the in-process scan fallback still applies.
 */
final class FireflyCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $dir = FireflyCachePaths::dir($this->app);

        // Category B: $app->instance() overrides each *WiringProvider's bound()-guarded empty default. instance()
        // is UNCONDITIONAL and every capability's default is `if (! bound(X)) singleton(X, empty)`, so the loaded
        // manifest wins REGARDLESS of provider registration order. Each ::load() is called explicitly (not through
        // a generic class-string helper) so its concrete return type flows into instance() type-safely.
        if (is_file($path = $dir.'/'.FireflyCachePaths::ROUTES)) {
            $this->app->instance(RouteManifest::class, RouteManifest::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::EXCEPTION_HANDLERS)) {
            $this->app->instance(ExceptionHandlerRegistry::class, ExceptionHandlerRegistry::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::HANDLERS)) {
            $this->app->instance(HandlerManifest::class, HandlerManifest::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::EVENT_LISTENERS)) {
            $this->app->instance(EventListenerManifest::class, EventListenerManifest::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::MESSAGE_LISTENERS)) {
            $this->app->instance(MessageListenerManifest::class, MessageListenerManifest::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::SCHEDULED)) {
            $this->app->instance(ScheduledManifest::class, ScheduledManifest::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::SECURITY_METHODS)) {
            $this->app->instance(SecurityMethodManifest::class, SecurityMethodManifest::load($path));
        }
        if (is_file($path = $dir.'/'.FireflyCachePaths::CONSTRAINTS)) {
            $this->app->instance(ConstraintManifest::class, ConstraintManifest::load($path));
        }

        // #[ConfigProperties] (BLOCKER-1 fix): the ONLY app-side binding path is ConfigRegistrar::register($manifest)
        // — the boot pipeline's sole call (FlushDefinitionsPass) always passes an EMPTY manifest, so #[ConfigProperties]
        // DTOs are otherwise unresolvable after ANY boot. Bind them here from the cached config-properties.php.
        // ConfigRegistrar's per-container "firefly.config.registered" guard makes the LATER empty-manifest boot call a
        // no-op regardless of registration order. CACHED-PATH-ONLY: dev (uncached) boot still leaves #[ConfigProperties]
        // unbound — a pre-existing framework limitation, not introduced by M14.
        $configProperties = $dir.'/'.FireflyCachePaths::CONFIG_PROPERTIES;
        if (is_file($configProperties)) {
            // ConfigRegistrar requires the concrete Illuminate container; $app is only typed to the Application
            // CONTRACT (same narrowing idiom as FireflyAutoConfigureServiceProvider::bindBootContextAndKernel()).
            // At runtime $app is always the concrete container, so this narrows honestly rather than via a cast.
            $container = $this->app;
            if (! $container instanceof Container) {
                throw new LogicException('FireflyCacheServiceProvider requires the concrete Illuminate container.');
            }

            /** @var Repository $repository */
            $repository = $container->make('config');

            (new ConfigRegistrar($container, new Config($repository)))
                ->register(ConfigPropertiesManifest::load($configProperties));
        }

        // Proxies: register a classmap autoloader so TransactionalBeanPostProcessor's class_exists($proxyClass)
        // is satisfied BEFORE it throws. register() runs at provider-register, before any boot-pass bean resolution.
        $proxyMap = $dir.'/'.FireflyCachePaths::PROXY_MAP;
        if (is_file($proxyMap)) {
            /** @var array<class-string,string> $map */
            $map = require $proxyMap;
            spl_autoload_register(static function (string $class) use ($map): void {
                if (isset($map[$class]) && is_file($map[$class])) {
                    require $map[$class];
                }
            });
        }
    }
}
