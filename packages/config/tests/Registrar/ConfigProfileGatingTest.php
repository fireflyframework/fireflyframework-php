<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Config\Registrar\ConfigRegistrar;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Config\Tests\Fixtures\AuditProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * The config-side half of honouring #[Profile]. Before this, the attribute was inert everywhere:
 * `grep -rn 'Profile::class' packages/<any>/src` matched nothing, so #[Profile('prod')] on a
 * #[ConfigProperties] DTO registered the DTO in every profile — the precise opposite of what the
 * annotation says. Here the requirement recorded by ConfigPropertiesScanner is finally acted on:
 * the registrar does not bind a DTO whose profiles are not active, so injecting it fails loudly
 * (Spring's NoSuchBeanDefinitionException shape) instead of silently handing back a bean that was
 * meant to be excluded.
 */
function profiledContainer(Profiles $profiles): Container
{
    $config = new Config(new Repository([
        'mail' => ['host' => 'smtp.example.com'],
        'audit' => ['enabled' => true, 'sink' => 'syslog'],
    ]));
    $descriptors = (new ConfigPropertiesScanner)->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $container = new Container;
    (new ConfigRegistrar($container, $config, profiles: $profiles))->register(new ConfigPropertiesManifest($descriptors));

    return $container;
}

it('does not register a profile-gated DTO when none of its profiles is active', function () {
    $container = profiledContainer(new Profiles(['dev']));

    expect($container->bound(AuditProperties::class))->toBeFalse()
        // Unconstrained DTOs are unaffected — gating must never be an all-or-nothing switch:
        ->and($container->bound(MailProperties::class))->toBeTrue();
});

it('registers a profile-gated DTO when one of its profiles is active', function () {
    $container = profiledContainer(new Profiles(['staging']));

    expect($container->bound(AuditProperties::class))->toBeTrue()
        ->and($container->make(AuditProperties::class)->enabled)->toBeTrue()
        ->and($container->make(AuditProperties::class)->sink)->toBe('syslog');
});

it('resolves the active profiles itself when the caller does not supply them', function () {
    // FlushDefinitionsPass constructs the registrar with two arguments today, so the profile
    // argument has to be optional; when it is omitted the registrar must still gate correctly
    // rather than fall open. ProfileResolver reads FIREFLY_PROFILES_ACTIVE here.
    putenv('FIREFLY_PROFILES_ACTIVE=prod');

    try {
        $config = new Config(new Repository(['audit' => ['enabled' => true]]));
        $descriptors = (new ConfigPropertiesScanner)->scan([
            'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
        ]);
        $container = new Container;
        (new ConfigRegistrar($container, $config))->register(new ConfigPropertiesManifest($descriptors));

        expect($container->bound(AuditProperties::class))->toBeTrue();
    } finally {
        putenv('FIREFLY_PROFILES_ACTIVE');
    }
});
