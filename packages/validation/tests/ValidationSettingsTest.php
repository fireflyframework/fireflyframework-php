<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Validation\MessageStyle;
use Firefly\Validation\ValidationSettings;
use Illuminate\Config\Repository;

it('defaults to the constraint style when the key is absent', function () {
    $settings = ValidationSettings::fromConfig(new Config(new Repository(['firefly' => []])));

    expect($settings->messages)->toBe(MessageStyle::Constraint)
        ->and((new ValidationSettings)->messages)->toBe(MessageStyle::Constraint);
});

it('reads the laravel style back', function () {
    $settings = ValidationSettings::fromConfig(new Config(new Repository(['firefly' => ['validation' => ['messages' => 'laravel']]])));

    expect($settings->messages)->toBe(MessageStyle::Laravel);
});

it('refuses a style it does not know, naming the key and the two it does', function () {
    expect(fn () => ValidationSettings::fromConfig(new Config(new Repository(['firefly' => ['validation' => ['messages' => 'symfony']]]))))
        ->toThrow(ConfigurationException::class, 'firefly.validation.messages')
        ->toThrow(ConfigurationException::class, '"constraint", "laravel"');
});
