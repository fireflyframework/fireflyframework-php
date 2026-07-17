<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Component;
use Firefly\Web\Attributes\DeleteMapping;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\Mapping;
use Firefly\Web\Attributes\PatchMapping;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\PutMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

it('RestController is a Component stereotype (auto-registered by the existing scanner)', function () {
    $target = new #[RestController] class {};

    $attrs = (new ReflectionObject($target))->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF);

    expect($attrs)->toHaveCount(1);
    expect($attrs[0]->newInstance())->toBeInstanceOf(RestController::class);
});

it('RequestMapping carries a class-level base path', function () {
    expect((new RequestMapping('/accounts'))->path)->toBe('/accounts')
        ->and((new RequestMapping)->path)->toBe('');
});

it('each verb mapping exposes its HTTP method, path, status and name', function (Mapping $mapping, string $method) {
    // Mapping only declares method(): the concrete verb classes carry $path/$status/$name (PHP interfaces
    // cannot declare properties), so narrow to the known implementers before touching them.
    if (! ($mapping instanceof GetMapping
        || $mapping instanceof PostMapping
        || $mapping instanceof PutMapping
        || $mapping instanceof PatchMapping
        || $mapping instanceof DeleteMapping)) {
        throw new RuntimeException('Expected a concrete verb mapping.');
    }

    expect($mapping->method())->toBe($method)
        ->and($mapping->path)->toBe('/x')
        ->and($mapping->status)->toBe(201)
        ->and($mapping->name)->toBe('r');
})->with([
    'GET' => [new GetMapping('/x', 201, 'r'), 'GET'],
    'POST' => [new PostMapping('/x', 201, 'r'), 'POST'],
    'PUT' => [new PutMapping('/x', 201, 'r'), 'PUT'],
    'PATCH' => [new PatchMapping('/x', 201, 'r'), 'PATCH'],
    'DELETE' => [new DeleteMapping('/x', 201, 'r'), 'DELETE'],
]);

it('verb mappings default to status 200 and no name', function () {
    expect((new GetMapping)->status)->toBe(200)
        ->and((new GetMapping)->name)->toBeNull()
        ->and((new GetMapping)->path)->toBe('');
});
