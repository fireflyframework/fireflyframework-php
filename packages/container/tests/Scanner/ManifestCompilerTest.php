<?php

declare(strict_types=1);

use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Scanner\ManifestCompiler;
use Firefly\Container\Tests\Fixtures\EnglishGreeter;

it('compiles a scan to a cached PHP file and loads it back without reflection', function () {
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ManifestCompiler)->write($components, $path);

    expect(file_exists($path))->toBeTrue();
    // The cached artifact is a plain array literal — no class references executed at load.
    expect(require $path)->toBeArray();

    $manifest = ComponentManifest::load($path);
    $classes = array_map(static fn ($d) => $d->class, $manifest->components);

    expect($manifest)->toBeInstanceOf(ComponentManifest::class)
        ->and($classes)->toContain(EnglishGreeter::class)
        ->and(count($manifest->components))->toBe(count($components));

    unlink($path);
});
