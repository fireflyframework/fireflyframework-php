<?php

declare(strict_types=1);

use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\Resolver\Tag;
use Firefly\Web\Tests\Fixtures\Resolver\TagController;

it('records a parameter\'s attributes and nullability, and treats an interface type as a service', function () {
    $routes = (new RouteScanner)->scan(['Firefly\\Web\\Tests\\Fixtures\\Resolver\\' => __DIR__.'/../Fixtures/Resolver']);
    $route = array_values(array_filter($routes, static fn ($r): bool => $r->controllerClass === TagController::class))[0];
    [$tag, $who] = $route->bindings;

    expect($tag['name'])->toBe('tag')
        ->and($tag['attributes'] ?? null)->toBe([Tag::class])
        ->and($tag)->not->toHaveKey('nullable')
        ->and($who['kind'])->toBe('service')
        ->and($who['type'])->toBe(Countable::class)
        ->and($who['nullable'] ?? null)->toBeTrue()
        ->and($who)->not->toHaveKey('attributes');
});
