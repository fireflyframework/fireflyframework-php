<?php

declare(strict_types=1);

use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestHeader;
use Firefly\Web\Attributes\UploadedFile;

it('models each binding attribute', function () {
    expect((new PathVariable('id'))->name)->toBe('id')
        ->and((new PathVariable)->name)->toBeNull()
        ->and((new QueryParam('page', 1, true))->name)->toBe('page')
        ->and((new QueryParam('page', 1, true))->default)->toBe(1)
        ->and((new QueryParam('page', 1, true))->required)->toBeTrue()
        ->and((new QueryParam)->required)->toBeFalse()
        ->and(new RequestBody)->toBeInstanceOf(RequestBody::class)
        ->and((new RequestHeader('X-Api-Key', 'none'))->name)->toBe('X-Api-Key')
        ->and((new RequestHeader('X-Api-Key', 'none'))->default)->toBe('none')
        ->and((new UploadedFile('avatar'))->name)->toBe('avatar');
});

it('restricts each binding attribute to parameter targets only', function () {
    $classes = [PathVariable::class, QueryParam::class, RequestBody::class, RequestHeader::class, UploadedFile::class];

    foreach ($classes as $class) {
        $reflectionClass = new ReflectionClass($class);
        $attributes = $reflectionClass->getAttributes(Attribute::class);

        expect($attributes)->toHaveCount(1);

        /** @var Attribute $instance */
        $instance = $attributes[0]->newInstance();

        expect($instance->flags)->toBe(Attribute::TARGET_PARAMETER);
    }
});
