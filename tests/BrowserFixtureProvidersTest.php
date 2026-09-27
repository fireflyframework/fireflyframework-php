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
 * discovery — derived from the root manifest rather than typed by hand, so adding a provider to fireflyframework/larafly
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

it('names every provider declared by the root library', function (): void {
    /** @var array{extra: array{laravel: array{providers: list<string>}}} $root */
    $root = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $declared = array_filter($root['extra']['laravel']['providers'], static fn (string $provider): bool => $provider !== FireflyAutoConfigureServiceProvider::class);

    expect(array_diff($declared, DiscoveredProviders::forSkeleton()))->toBe([]);
});
