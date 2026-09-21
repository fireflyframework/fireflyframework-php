<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Context\Scan\AppScan;
use Illuminate\Contracts\Container\Container;

/**
 * Makes the generated #[Transactional] proxy classes loadable, on both boot paths.
 *
 * TransactionalBeanPostProcessor throws when the manifest promises a proxy whose class is not loaded, so a
 * non-empty TransactionalManifest is only safe once the proxies are reachable. Two sources:
 *
 *   - CACHED: firefly:cache emitted proxies/<mangled>.php plus a proxies.php classmap. firefly/cli's
 *     FireflyCacheServiceProvider registers an autoloader for it — but firefly/cli is optional, so we
 *     register the same classmap here. Registering twice is harmless: the second autoloader never fires
 *     because the first already declared the class.
 *   - UNCACHED: nothing has been generated at all. We rescan through every AdviceSource and materialise each
 *     proxy the plan names through ProxyClassGenerator::load(), which writes into a private per-process 0700
 *     directory with O_EXCL and requires it. Dev-time cost only: a cached app — one holding proxy-plan.php, or
 *     the transactional.php + proxies.php a firefly:cache from before that file existed emitted, from which
 *     DataAutoConfiguration::proxyPlan() bridges a transactional-only plan — never reaches this branch.
 *
 * Before this existed, DataAutoConfiguration bound an unconditional empty TransactionalManifest and nothing
 * ever loaded the compiled transactional.php, so hasProxyFor() was always false and #[Transactional] was a
 * silent no-op unless the application hand-wrote its own manifest configuration — which is exactly what the
 * skeleton's app/Support/CachedTransactionalConfiguration.php had to do.
 */
final class ProxyMaterializer
{
    /** @var array<string,true> guards against re-registering the classmap autoloader on the same container */
    private static array $registered = [];

    public static function classmap(Container $app): void
    {
        $map = AppScan::cachedFile($app, AppScan::PROXY_MAP);
        if ($map === null || isset(self::$registered[$map])) {
            return;
        }
        self::$registered[$map] = true;

        /** @var mixed $loaded */
        $loaded = require $map;
        if (! is_array($loaded)) {
            return;
        }

        /** @var array<string,string> $classmap */
        $classmap = $loaded;
        spl_autoload_register(static function (string $class) use ($classmap): void {
            if (isset($classmap[$class]) && is_file($classmap[$class])) {
                require $classmap[$class];
            }
        });
    }

    /**
     * Generate + require every proxy the plan names. Used only when no compiled classmap exists.
     */
    public static function materialize(ProxyPlanner $planner, ProxyPlan $plan): void
    {
        $generator = new ProxyClassGenerator;
        foreach ($planner->proxyMethods($plan) as $targetClass => $methods) {
            $generator->load($targetClass, $methods);
        }
    }
}
