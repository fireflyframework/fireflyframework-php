<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteManifestCompiler;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\CreateAccountRequest;

it('round-trips scanned descriptors through compile -> write -> load', function () {
    $psr4 = ['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures'];
    $scanned = (new RouteScanner)->scan($psr4);
    $path = sys_get_temp_dir().'/fw-routes-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new RouteManifestCompiler)->write($scanned, $path);
        $manifest = RouteManifest::load($path);

        $reloaded = array_map(fn ($d) => $d->toArray(), $manifest->all());
        $expected = array_map(fn ($d) => $d->toArray(), $scanned);

        expect($reloaded)->toBe($expected);

        $create = collect($manifest->all())->firstWhere('methodName', 'create');

        if (! $create instanceof RouteDescriptor) {
            throw new RuntimeException('create route descriptor not found in manifest.');
        }

        expect($create->bindings[0]['type'])->toBe(CreateAccountRequest::class)
            ->and($create->bindings[0]['valid'])->toBeTrue()
            ->and($create->bindings[0]['properties'])->toBe(['iban', 'owner']);
    } finally {
        @unlink($path);
    }
});

it('throws when the manifest file is missing', function () {
    RouteManifest::load('/no/such/route-manifest.php');
})->throws(ConfigurationException::class);
