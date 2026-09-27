<?php

declare(strict_types=1);

namespace Firefly\Tests\Support;

use Illuminate\Support\ServiceProvider;

/** Gives Larastan the same provider metadata that an installed consumer discovers. */
final class PackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var array{extra: array{laravel: array{providers: list<class-string<ServiceProvider>>}}} $manifest */
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['extra']['laravel']['providers'] as $provider) {
            $this->app->register($provider);
        }
    }
}
