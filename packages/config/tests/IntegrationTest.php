<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Registrar\ConfigRegistrar;
use Firefly\Config\Scanner\ConfigManifestCompiler;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Config\Tests\Fixtures\DatabaseProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;
use Firefly\Config\Value\ConfigValueResolver;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Value\ValueResolver;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('binds DTOs from a cached manifest and keeps the config resolver under the container registrar backoff', function () {
    $config = new Config(new Repository([
        'mail' => ['host' => 'smtp.example.com', 'port' => 587, 'tls' => true],
        'database' => ['driver' => 'pgsql', 'pool' => ['min' => 2, 'max' => 20], 'replicas' => ['r1']],
    ]));

    // Compile the config-properties manifest to disk, then load it back (Octane path).
    $descriptors = (new ConfigPropertiesScanner)->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/Fixtures',
    ]);
    $path = sys_get_temp_dir().'/firefly-config-int-'.bin2hex(random_bytes(6)).'.php';
    (new ConfigManifestCompiler)->write($descriptors, $path);
    $manifest = ConfigPropertiesManifest::load($path);

    $container = new Container;

    // 1. Config registers first — installs ConfigValueResolver + DTO bindings.
    (new ConfigRegistrar($container, $config))->register($manifest);

    // 2. The M2 container registrar runs afterward; its registerValueSupport() backs off
    //    because ValueResolver is already bound, so the config resolver survives.
    (new ContainerRegistrar($container))->register(new ComponentManifest([]));

    // Config-backed resolver is still in place:
    expect($container->make(ValueResolver::class))->toBeInstanceOf(ConfigValueResolver::class)
        ->and($container->make(ValueResolver::class)->resolve('${mail.host}'))->toBe('smtp.example.com');

    // DTOs are bound from config, including the nested one:
    $mail = $container->make(MailProperties::class);
    $db = $container->make(DatabaseProperties::class);
    expect($mail->port)->toBe(587)
        ->and($mail->tls)->toBeTrue()
        ->and($db->driver)->toBe('pgsql')
        ->and($db->pool->max)->toBe(20)
        ->and($db->replicas)->toBe(['r1']);

    unlink($path);
});
