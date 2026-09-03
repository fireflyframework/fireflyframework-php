<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Config\Registrar\ConfigRegistrar;
use Firefly\Config\Scanner\ConfigManifestCompiler;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Config\Tests\Fixtures\AuditProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

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

/**
 * The profile requirement has to survive the artifact, not merely the descriptor object: the whole
 * point of recording it at scan time is that a production boot reads it out of a compiled file with
 * no reflection at all. This walks the full path — scan, compile to disk, load back, register —
 * and asserts the gate still fires on the far side.
 */
it('carries a #[Profile] requirement through the compiled artifact and gates registration on it', function () {
    $descriptors = (new ConfigPropertiesScanner)->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-config-profile-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ConfigManifestCompiler)->write($descriptors, $path);

    $manifest = ConfigPropertiesManifest::load($path);
    $profilesByClass = [];
    foreach ($manifest->properties as $descriptor) {
        $profilesByClass[$descriptor->class] = $descriptor->profiles;
    }

    expect($profilesByClass[AuditProperties::class])->toBe(['prod', 'staging'])
        ->and($profilesByClass[MailProperties::class])->toBe([]);

    $config = new Config(new Repository(['audit' => ['enabled' => true]]));

    $excluded = new Container;
    (new ConfigRegistrar($excluded, $config, profiles: new Profiles(['dev'])))->register($manifest);

    $included = new Container;
    (new ConfigRegistrar($included, $config, profiles: new Profiles(['prod'])))->register($manifest);

    expect($excluded->bound(AuditProperties::class))->toBeFalse()
        ->and($included->bound(AuditProperties::class))->toBeTrue();

    unlink($path);
});
