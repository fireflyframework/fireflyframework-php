<?php

declare(strict_types=1);

use Firefly\Context\Condition\Attributes\ConditionalOnBean;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProfile;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Condition\BeanConditionAttribute;
use Firefly\Context\Condition\ConditionAttribute;

it('constructs #[ConditionalOnProperty] and exposes its data', function () {
    $attr = new ConditionalOnProperty(name: 'firefly.feature.enabled', havingValue: 'true', matchIfMissing: true);

    expect($attr->name)->toBe('firefly.feature.enabled')
        ->and($attr->havingValue)->toBe('true')
        ->and($attr->matchIfMissing)->toBeTrue();
});

it('defaults #[ConditionalOnProperty] havingValue/matchIfMissing', function () {
    $attr = new ConditionalOnProperty(name: 'firefly.feature.enabled');

    expect($attr->havingValue)->toBeNull()
        ->and($attr->matchIfMissing)->toBeFalse();
});

it('constructs #[ConditionalOnClass] and exposes its data (class may legitimately not exist)', function () {
    $attr = new ConditionalOnClass(class: 'Some\Vendor\Package\That\Might\Not\Exist');

    expect($attr->class)->toBe('Some\Vendor\Package\That\Might\Not\Exist');
});

it('constructs #[ConditionalOnMissingClass] and exposes its data', function () {
    $attr = new ConditionalOnMissingClass(class: 'Some\Vendor\Package\That\Might\Not\Exist');

    expect($attr->class)->toBe('Some\Vendor\Package\That\Might\Not\Exist');
});

it('constructs #[ConditionalOnProfile] with a variadic list of profiles', function () {
    $attr = new ConditionalOnProfile('local', 'testing');

    expect($attr->profiles)->toBe(['local', 'testing']);
});

it('constructs #[ConditionalOnProfile] with zero profiles', function () {
    $attr = new ConditionalOnProfile;

    expect($attr->profiles)->toBe([]);
});

it('constructs #[ConditionalOnBean] and exposes its data', function () {
    $attr = new ConditionalOnBean(type: 'App\Contracts\PaymentGateway');

    expect($attr->type)->toBe('App\Contracts\PaymentGateway');
});

it('constructs #[ConditionalOnMissingBean] and exposes its data', function () {
    $attr = new ConditionalOnMissingBean(type: 'App\Contracts\PaymentGateway');

    expect($attr->type)->toBe('App\Contracts\PaymentGateway');
});

it('marks the four registry-independent conditions as ConditionAttribute but NOT BeanConditionAttribute', function () {
    expect(new ConditionalOnProperty(name: 'x'))->toBeInstanceOf(ConditionAttribute::class)
        ->and(new ConditionalOnProperty(name: 'x'))->not->toBeInstanceOf(BeanConditionAttribute::class)
        ->and(new ConditionalOnClass(class: 'X'))->toBeInstanceOf(ConditionAttribute::class)
        ->and(new ConditionalOnClass(class: 'X'))->not->toBeInstanceOf(BeanConditionAttribute::class)
        ->and(new ConditionalOnMissingClass(class: 'X'))->toBeInstanceOf(ConditionAttribute::class)
        ->and(new ConditionalOnMissingClass(class: 'X'))->not->toBeInstanceOf(BeanConditionAttribute::class)
        ->and(new ConditionalOnProfile('x'))->toBeInstanceOf(ConditionAttribute::class)
        ->and(new ConditionalOnProfile('x'))->not->toBeInstanceOf(BeanConditionAttribute::class);
});

it('marks the two bean-dependent conditions as BOTH ConditionAttribute and BeanConditionAttribute', function () {
    expect(new ConditionalOnBean(type: 'X'))->toBeInstanceOf(ConditionAttribute::class)
        ->and(new ConditionalOnBean(type: 'X'))->toBeInstanceOf(BeanConditionAttribute::class)
        ->and(new ConditionalOnMissingBean(type: 'X'))->toBeInstanceOf(ConditionAttribute::class)
        ->and(new ConditionalOnMissingBean(type: 'X'))->toBeInstanceOf(BeanConditionAttribute::class);
});

it('declares all six condition attributes as repeatable, targeting class + method', function () {
    $classes = [
        ConditionalOnProperty::class,
        ConditionalOnClass::class,
        ConditionalOnMissingClass::class,
        ConditionalOnProfile::class,
        ConditionalOnBean::class,
        ConditionalOnMissingBean::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Attribute::class);

        expect($attributes)->toHaveCount(1);

        /** @var Attribute $meta */
        $meta = $attributes[0]->newInstance();

        expect($meta->flags & Attribute::TARGET_CLASS)->not->toBe(0)
            ->and($meta->flags & Attribute::TARGET_METHOD)->not->toBe(0)
            ->and($meta->flags & Attribute::IS_REPEATABLE)->not->toBe(0);
    }
});

it('declares each condition attribute as final readonly', function () {
    $classes = [
        ConditionalOnProperty::class,
        ConditionalOnClass::class,
        ConditionalOnMissingClass::class,
        ConditionalOnProfile::class,
        ConditionalOnBean::class,
        ConditionalOnMissingBean::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }
});
