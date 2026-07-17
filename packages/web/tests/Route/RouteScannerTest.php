<?php

declare(strict_types=1);

use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\AccountsController;
use Firefly\Web\Tests\Fixtures\CreateAccountRequest;

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
