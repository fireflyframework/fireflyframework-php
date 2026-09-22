<?php

declare(strict_types=1);

use Firefly\Admin\AdminServiceProvider;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Cli\Boot\FireflyCacheServiceProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Tests\Browser\Support\DiscoveredProviders;
use Firefly\Web\WebServiceProvider;

/**
 * The browser suite boots the skeleton with the provider set a CREATED app gets from Laravel's package
 * discovery — derived from installed.json rather than typed by hand, so adding a package to firefly/firefly
 * changes the browser fixture without anyone remembering to. This test runs in the default gate (no
 * browser) and pins the derivation.
 */
it('derives the created-app provider set from the packages the skeleton requires', function (): void {
    $providers = DiscoveredProviders::forSkeleton();

    expect($providers)->toContain(WebServiceProvider::class)
        ->toContain(AdminServiceProvider::class)
        ->toContain(SecurityServiceProvider::class)
        // Testbench registers AutoConfigure first itself; a second registration is skipped by Laravel but
        // it would still be a lie about what the fixture adds.
        ->not->toContain(FireflyAutoConfigureServiceProvider::class)
        ->and(end($providers))->toBe(FireflyCacheServiceProvider::class)
        ->and(array_count_values($providers))->each->toBe(1);
});

it('names every provider a package in the metapackage declares', function (): void {
    /** @var array{packages: list<array{name: string, extra?: array{laravel?: array{providers?: list<string>}}}>} $installed */
    $installed = json_decode((string) file_get_contents(__DIR__.'/../vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    /** @var array{require?: array<string, string>} $meta */
    $meta = json_decode((string) file_get_contents(__DIR__.'/../packages/firefly/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    $declared = [];
    foreach ($installed['packages'] as $package) {
        if (isset($meta['require'][$package['name']])) {
            foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                if ($provider !== FireflyAutoConfigureServiceProvider::class) {
                    $declared[] = $provider;
                }
            }
        }
    }

    expect(array_diff($declared, DiscoveredProviders::forSkeleton()))->toBe([]);
});
