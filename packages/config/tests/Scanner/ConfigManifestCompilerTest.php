<?php

declare(strict_types=1);

use Firefly\Config\Scanner\ConfigManifestCompiler;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Config\Tests\Fixtures\MailProperties;

it('compiles a scan to a cached array file and loads it back without reflection', function () {
    $descriptors = (new ConfigPropertiesScanner)->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-config-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ConfigManifestCompiler)->write($descriptors, $path);

    expect(require $path)->toBeArray();

    $manifest = ConfigPropertiesManifest::load($path);
    $classes = array_map(static fn ($d) => $d->class, $manifest->properties);
    $prefixesByClass = array_combine(
        array_map(static fn ($d) => $d->class, $manifest->properties),
        array_map(static fn ($d) => $d->prefix, $manifest->properties),
    );

    expect($manifest)->toBeInstanceOf(ConfigPropertiesManifest::class)
        ->and($classes)->toContain(MailProperties::class)
        ->and(count($manifest->properties))->toBe(count($descriptors))
        ->and($prefixesByClass[MailProperties::class])->toBe('mail');

    unlink($path);
});
