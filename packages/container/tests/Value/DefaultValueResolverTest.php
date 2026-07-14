<?php

declare(strict_types=1);

use Firefly\Container\Value\DefaultValueResolver;

it('resolves ${ENV:default} from the environment with a fallback', function () {
    putenv('FIREFLY_TEST_VALUE=from-env');
    $resolver = new DefaultValueResolver;

    expect($resolver->resolve('${FIREFLY_TEST_VALUE:fallback}'))->toBe('from-env')
        ->and($resolver->resolve('${FIREFLY_MISSING_VALUE:fallback}'))->toBe('fallback')
        ->and($resolver->resolve('${FIREFLY_MISSING_NO_DEFAULT}'))->toBeNull();

    putenv('FIREFLY_TEST_VALUE');
});

it('evaluates #{expression} via the expression language', function () {
    $resolver = new DefaultValueResolver;
    expect($resolver->resolve('#{1 + 2}'))->toBe(3)
        ->and($resolver->resolve('#{"a" ~ "b"}'))->toBe('ab');
});

it('returns a literal string unchanged', function () {
    expect((new DefaultValueResolver)->resolve('plain'))->toBe('plain');
});
