<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\AccountsController;
use Firefly\Web\Tests\Fixtures\CreateAccountRequest;
use Firefly\Web\Tests\Fixtures\RoomsController;

/** @return array<string, RouteDescriptor> keyed by "METHOD path" */
function scannedRoutes(): array
{
    $psr4 = ['Firefly\\Web\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures'];
    $keyed = [];
    foreach ((new RouteScanner)->scan($psr4) as $descriptor) {
        $keyed[$descriptor->httpMethod.' '.$descriptor->path] = $descriptor;
    }

    return $keyed;
}

it('builds a descriptor per mapped method with the class base path prepended', function () {
    $routes = scannedRoutes();

    expect($routes)->toHaveKey('GET /accounts/{id}')
        ->and($routes)->toHaveKey('POST /accounts');

    $show = $routes['GET /accounts/{id}'];
    expect($show->controllerClass)->toBe(AccountsController::class)
        ->and($show->methodName)->toBe('show')
        ->and($show->status)->toBe(200)
        ->and($show->name)->toBe('accounts.show');

    $create = $routes['POST /accounts'];
    expect($create->status)->toBe(201)
        ->and($create->name)->toBeNull();
});

it('reflects a binding plan per parameter in declaration order', function () {
    $show = scannedRoutes()['GET /accounts/{id}'];

    expect($show->bindings[0])->toBe(['name' => 'id', 'kind' => 'path', 'key' => 'id', 'type' => 'int', 'required' => true, 'default' => null, 'valid' => false, 'properties' => []])
        ->and($show->bindings[1])->toBe(['name' => 'view', 'kind' => 'query', 'key' => 'view', 'type' => 'string', 'required' => false, 'default' => 'summary', 'valid' => false, 'properties' => []])
        ->and($show->bindings[2])->toBe(['name' => 'trace', 'kind' => 'header', 'key' => 'X-Trace', 'type' => 'string', 'required' => false, 'default' => null, 'valid' => false, 'properties' => []]);
});

it('marks a #[Valid] #[RequestBody] with the DTO type, valid flag, and reflection-free hydration properties', function () {
    $create = scannedRoutes()['POST /accounts']->bindings[0];

    expect($create['kind'])->toBe('body')
        ->and($create['type'])->toBe(CreateAccountRequest::class)
        ->and($create['valid'])->toBeTrue()
        ->and($create['properties'])->toBe(['iban', 'owner']);
});

it('compiles a #[PathVariable] pattern and its 404 code and sentence into the binding plan', function () {
    $descriptors = (new RouteScanner)->scan(['Firefly\\Web\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures']);
    $show = array_values(array_filter($descriptors, static fn ($d): bool => $d->controllerClass === RoomsController::class && $d->methodName === 'show'))[0];
    $member = array_values(array_filter($descriptors, static fn ($d): bool => $d->controllerClass === RoomsController::class && $d->methodName === 'member'))[0];

    expect($show->bindings[0])->toBe([
        'name' => 'roomId', 'kind' => 'path', 'key' => 'roomId', 'type' => 'string', 'required' => true, 'default' => null, 'valid' => false, 'properties' => [],
        'pattern' => PathVariable::UUID, 'notFoundCode' => 'ROOM_NOT_FOUND', 'notFoundMessage' => 'That room does not exist, or is not yours.',
    ])
        // Only the keys that were given are emitted, so a plan for an unpatterned variable — and a manifest
        // compiled before the keys existed — is byte-identical to what the scanner produced before.
        ->and($member->bindings[1])->toBe([
            'name' => 'memberId', 'kind' => 'path', 'key' => 'memberId', 'type' => 'string', 'required' => true, 'default' => null, 'valid' => false, 'properties' => [],
            'pattern' => '[0-9]+',
        ]);
});

it('refuses a #[PathVariable] pattern that is not a valid regular expression at scan time', function () {
    expect(fn () => (new RouteScanner)->scan(['Firefly\\Web\\Tests\\MalformedFixtures\\' => __DIR__.'/../MalformedFixtures']))
        ->toThrow(ConfigurationException::class, 'BrokenPatternController::show');
});
