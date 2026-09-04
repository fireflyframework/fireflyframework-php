<?php

declare(strict_types=1);

namespace Firefly\Context\Scan;

use Firefly\Config\Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The app-scan seam every capability package uses to resolve its own Category-B manifest.
 *
 * Before this class each capability bound an EMPTY manifest behind a bound() guard and relied on
 * firefly/cli's FireflyCacheServiceProvider to $app->instance() the compiled artifact over it. That made
 * firefly/cli — a require-dev tool — the sole owner of the LOADING half of the contract, so an app that had
 * not run `firefly:cache` (or that installed the firefly/firefly metapackage, which does not require the CLI)
 * booted with routes, handlers, listeners, scheduled tasks, constraints and method-security rules all silently
 * empty. Routes 404'd; method security failed OPEN.
 *
 * The convention here mirrors FireflyAutoConfigureServiceProvider::computeAppManifests(), which has always
 * done the right thing for the component/context manifests:
 *
 *   1. compiled artifact present in the cache dir  -> load it, zero reflection (production)
 *   2. otherwise `firefly.scan.paths` is non-empty -> scan the PSR-4 roots in-process (development)
 *   3. otherwise                                    -> an empty manifest, and boot still succeeds
 *
 * firefly/cli keeps emitting the artifacts and keeps its own loader (harmless now — it binds the same objects
 * through $app->instance(), which still wins), but it is no longer required for an app to work.
 *
 * The cache basenames are duplicated from Firefly\Cli\Cache\FireflyCachePaths on purpose: Context sits far
 * below Cli in the layer graph and must not depend on it. CachePathsParityTest pins the two lists together.
 */
final class AppScan
{
    public const string COMPONENT = 'component.php';

    public const string CONTEXT = 'context.php';

    public const string CONFIG_PROPERTIES = 'config-properties.php';

    public const string ROUTES = 'routes.php';

    public const string EXCEPTION_HANDLERS = 'exception-handlers.php';

    public const string CONSTRAINTS = 'constraints.php';

    public const string HANDLERS = 'handlers.php';

    public const string EVENT_LISTENERS = 'event-listeners.php';

    public const string MESSAGE_LISTENERS = 'message-listeners.php';

    public const string SCHEDULED = 'scheduled.php';

    public const string SECURITY_METHODS = 'security-methods.php';

    public const string TRANSACTIONAL = 'transactional.php';

    public const string PROXY_MAP = 'proxies.php';

    /**
     * The app's PSR-4 scan roots (namespace-prefix => absolute directory), or [] when unconfigured.
     *
     * Takes the Illuminate container rather than a Firefly Config so that Validation — which is allowed to
     * depend on Context but NOT on Config — can call it without widening its layer.
     *
     * @return array<string,string>
     */
    public static function paths(Container $app): array
    {
        $paths = self::config($app)->get('firefly.scan.paths', []);
        if (! is_array($paths)) {
            return [];
        }

        $roots = [];
        foreach ($paths as $prefix => $dir) {
            if (is_string($prefix) && is_string($dir) && $prefix !== '' && $dir !== '') {
                $roots[$prefix] = $dir;
            }
        }

        return $roots;
    }

    /**
     * The absolute path of a compiled artifact when it exists, else null.
     *
     * Honours `firefly.cache.path` and falls back to the bootstrap/cache/firefly convention, matching
     * FireflyCachePaths::dir() so both loaders agree on where firefly:cache wrote.
     */
    public static function cachedFile(Container $app, string $basename): ?string
    {
        $path = self::dir($app).'/'.$basename;

        return is_file($path) ? $path : null;
    }

    public static function dir(Container $app): string
    {
        $configured = self::config($app)->get('firefly.cache.path');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $base = method_exists($app, 'basePath') ? $app->basePath('bootstrap/cache/firefly') : null;

        return is_string($base) ? $base : getcwd().'/bootstrap/cache/firefly';
    }

    /**
     * Every declared class under a PSR-4 map — the class-list source for scanners that compile from a class
     * list rather than a directory walk (validation's constraints). Mirrors Firefly\Cli\Cache\ClassEnumerator,
     * which now delegates here.
     *
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<class-string>
     */
    public static function classes(array $psr4): array
    {
        $classes = [];
        foreach ($psr4 as $prefix => $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $it */
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1, -4);
                $class = rtrim($prefix, '\\').'\\'.str_replace('/', '\\', $relative);
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    private static function config(Container $app): Config
    {
        /** @var Repository $repository */
        $repository = $app->get('config');

        return new Config($repository);
    }
}
