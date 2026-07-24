<?php

declare(strict_types=1);

use Firefly\Security\Password\BcryptPasswordEncoder;
use Firefly\Security\Password\DelegatingPasswordEncoder;
use Firefly\Security\Password\NoOpPasswordEncoder;

function delegating(): DelegatingPasswordEncoder
{
    return new DelegatingPasswordEncoder('bcrypt', [
        'bcrypt' => new BcryptPasswordEncoder,
        'noop' => new NoOpPasswordEncoder,
    ]);
}

it('encodes with the default id prefix and matches it', function () {
    $encoder = delegating();
    $encoded = $encoder->encode('s3cret');

    expect($encoded)->toStartWith('{bcrypt}')
        ->and($encoder->matches('s3cret', $encoded))->toBeTrue()
        ->and($encoder->matches('wrong', $encoded))->toBeFalse()
        ->and($encoder->upgradeEncoding($encoded))->toBeFalse();
});

it('matches a legacy non-default id and flags it for upgrade', function () {
    $encoder = delegating();

    expect($encoder->matches('pw', '{noop}pw'))->toBeTrue()
        ->and($encoder->upgradeEncoding('{noop}pw'))->toBeTrue();
});

it('fails closed on an unknown or missing id prefix', function () {
    $encoder = delegating();

    expect($encoder->matches('pw', '{unknown}pw'))->toBeFalse()
        ->and($encoder->matches('pw', 'no-prefix'))->toBeFalse();
});
