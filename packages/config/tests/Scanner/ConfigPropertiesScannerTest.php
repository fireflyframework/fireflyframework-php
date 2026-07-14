<?php

declare(strict_types=1);

use Firefly\Config\Scanner\ConfigPropertiesDescriptor;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Config\Tests\Fixtures\DatabaseProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;

it('discovers #[ConfigProperties] classes and their prefixes', function () {
    $descriptors = (new ConfigPropertiesScanner())->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $byClass = [];
    foreach ($descriptors as $d) {
        $byClass[$d->class] = $d->prefix;
    }

    expect($byClass[MailProperties::class])->toBe('mail')
        ->and($byClass[DatabaseProperties::class])->toBe('database')
        // Pool has no #[ConfigProperties] — not discovered:
        ->and($byClass)->not->toHaveKey(\Firefly\Config\Tests\Fixtures\Pool::class);
});

it('round-trips a descriptor', function () {
    $d = new ConfigPropertiesDescriptor(MailProperties::class, 'mail');
    expect(ConfigPropertiesDescriptor::fromArray($d->toArray()))->toEqual($d);
});
