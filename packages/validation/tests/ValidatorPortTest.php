<?php

declare(strict_types=1);

use Firefly\Validation\Valid;
use Firefly\Validation\Validator;

it('exposes a Validator port with a validate(data, rules): array signature', function () {
    $reflection = new ReflectionMethod(Validator::class, 'validate');

    expect(interface_exists(Validator::class))->toBeTrue()
        ->and($reflection->getNumberOfParameters())->toBe(2)
        ->and($reflection->getParameters()[0]->getName())->toBe('data')
        ->and($reflection->getParameters()[1]->getName())->toBe('rules')
        ->and((string) $reflection->getReturnType())->toBe('array');
});

it('declares #[Valid] as an inert parameter attribute (metadata only, no members)', function () {
    $attribute = new ReflectionClass(Valid::class);
    $attr = $attribute->getAttributes(Attribute::class)[0]->newInstance();

    expect($attr->flags & Attribute::TARGET_PARAMETER)->toBe(Attribute::TARGET_PARAMETER)
        ->and($attribute->getConstructor())->toBeNull();
});
