<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Registrar\ConfigRegistrar;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Config\Tests\Fixtures\MailProperties;
use Firefly\Config\Value\ConfigValueResolver;
use Firefly\Container\Value\ValueResolver;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function registeredConfigContainer(): Container
{
    $config = new Config(new Repository([
        'mail' => ['host' => 'smtp.example.com', 'port' => 2525, 'tls' => true],
    ]));
    $descriptors = (new ConfigPropertiesScanner)->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);
    $container = new Container;
    (new ConfigRegistrar($container, $config))->register(new ConfigPropertiesManifest($descriptors));

    return $container;
}

it('binds the ConfigValueResolver as the ValueResolver', function () {
    $c = registeredConfigContainer();

    expect($c->make(ValueResolver::class))->toBeInstanceOf(ConfigValueResolver::class);
});

it('registers a #[ConfigProperties] DTO bound from config', function () {
    $c = registeredConfigContainer();

    $mail = $c->make(MailProperties::class);
    expect($mail)->toBeInstanceOf(MailProperties::class)
        ->and($mail->host)->toBe('smtp.example.com')
        ->and($mail->port)->toBe(2525)
        ->and($mail->tls)->toBeTrue();
});
