<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Cli\Boot\FireflyCacheServiceProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** The root library's provider set, as Laravel discovers it in a consumer application. */
final class DiscoveredProviders
{
    /** @return list<class-string<ServiceProvider>> */
    public static function forSkeleton(): array
    {
        /** @var array{extra: array{laravel: array{providers: list<string>}}} $package */
        $package = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $providers = [];
        foreach ($package['extra']['laravel']['providers'] as $provider) {
            if ($provider === FireflyAutoConfigureServiceProvider::class || $provider === FireflyCacheServiceProvider::class) {
                continue;
            }
            if (! is_subclass_of($provider, ServiceProvider::class)) {
                throw new RuntimeException("{$provider} is not a ServiceProvider.");
            }
            $providers[] = $provider;
        }

        $providers[] = FireflyCacheServiceProvider::class;

        return array_values(array_unique($providers));
    }
}
