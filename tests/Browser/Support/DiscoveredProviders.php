<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Cli\Boot\FireflyCacheServiceProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The service providers a created LaraFly app registers through Laravel's package discovery.
 *
 * `composer create-project firefly/skeleton` installs `firefly/firefly` (the metapackage) and `firefly/cli`,
 * and Laravel discovers every provider those packages' composer.json files declare. The browser suite has
 * to boot the same set — a dashboard page that renders only because a provider is missing would be a false
 * green — so the list is READ from vendor/composer/installed.json rather than typed here, and stays true as
 * packages are added to the metapackage.
 *
 * Two adjustments: FireflyAutoConfigureServiceProvider is dropped because FireflyTestCase registers it first
 * itself, and FireflyCacheServiceProvider goes last because its unconditional `$app->instance()` overrides
 * must win over every *WiringProvider's bound()-guarded default.
 */
final class DiscoveredProviders
{
    private const array ROOTS = ['firefly/firefly', 'firefly/cli'];

    /** @return list<class-string<ServiceProvider>> */
    public static function forSkeleton(): array
    {
        /** @var array{packages: list<array{name: string, require?: array<string, string>, extra?: array{laravel?: array{providers?: list<string>}}}>} $installed */
        $installed = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/vendor/composer/installed.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $byName = [];
        foreach ($installed['packages'] as $package) {
            $byName[$package['name']] = $package;
        }

        $wanted = [];
        foreach (self::ROOTS as $root) {
            foreach (array_keys($byName[$root]['require'] ?? []) as $name) {
                if (str_starts_with($name, 'firefly/')) {
                    $wanted[$name] = true;
                }
            }
        }

        $providers = [];
        foreach ($byName as $name => $package) {
            if (! isset($wanted[$name])) {
                continue;
            }
            foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                if ($provider === FireflyAutoConfigureServiceProvider::class || $provider === FireflyCacheServiceProvider::class) {
                    continue;
                }
                if (! is_subclass_of($provider, ServiceProvider::class)) {
                    throw new RuntimeException("{$name} declares {$provider} as a provider, but it is not a ServiceProvider.");
                }
                $providers[] = $provider;
            }
        }

        $providers[] = FireflyCacheServiceProvider::class;

        return array_values(array_unique($providers));
    }
}
