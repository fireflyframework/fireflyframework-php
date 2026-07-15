<?php

declare(strict_types=1);

use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Lifecycle\PreDestroy;

it('declares #[PostConstruct] and #[PreDestroy] as final readonly, targeting methods only, with no constructor parameters', function () {
    $classes = [
        PostConstruct::class,
        PreDestroy::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();

        $constructor = $reflection->getConstructor();
        expect($constructor === null || $constructor->getNumberOfParameters() === 0)->toBeTrue();

        $attributes = $reflection->getAttributes(Attribute::class);
        expect($attributes)->toHaveCount(1);

        /** @var Attribute $meta */
        $meta = $attributes[0]->newInstance();
        expect($meta->flags)->toBe(Attribute::TARGET_METHOD);
    }
});

it('constructs #[PostConstruct] and #[PreDestroy] with zero arguments', function () {
    expect(new PostConstruct)->toBeInstanceOf(PostConstruct::class)
        ->and(new PreDestroy)->toBeInstanceOf(PreDestroy::class);
});
