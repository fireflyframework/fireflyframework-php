<?php

declare(strict_types=1);

use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\Resolver\Tag;
use Firefly\Web\Tests\Fixtures\Resolver\TagController;

it('records a parameter\'s attributes and nullability, and treats an interface type as a service', function () {
    $routes = (new RouteScanner)->scan(['Firefly\\Web\\Tests\\Fixtures\\Resolver\\' => __DIR__.'/../Fixtures/Resolver']);
    $route = array_values(array_filter($routes, static fn ($r): bool => $r->controllerClass === TagController::class && $r->methodName === 'show'))[0];
    [$tag, $who] = $route->bindings;

    // `mixed` takes null by PHP's own rules, and an attributed parameter is one a resolver may answer null
    // for, so the plan says so — whatever kind the type alone planned it as.
    expect($tag['name'])->toBe('tag')
        ->and($tag['attributes'] ?? null)->toBe([Tag::class])
        ->and($tag['nullable'] ?? null)->toBeTrue()
        ->and($who['kind'])->toBe('service')
        ->and($who['type'])->toBe(Countable::class)
        ->and($who['nullable'] ?? null)->toBeTrue()
        ->and($who)->not->toHaveKey('attributes');
});

it('records nullability for a scalar-typed attributed parameter, and nothing extra for an ordinary query parameter', function () {
    $routes = (new RouteScanner)->scan(['Firefly\\Web\\Tests\\Fixtures\\Resolver\\' => __DIR__.'/../Fixtures/Resolver']);
    $route = array_values(array_filter($routes, static fn ($r): bool => $r->controllerClass === TagController::class && $r->methodName === 'labelled'))[0];
    [$label, $page] = $route->bindings;

    // `#[Tag] ?string $label` plans as a query binding by its type; the attribute makes it a resolver's, and
    // the resolver must be able to see that null is allowed rather than refuse it as a missing value.
    expect($label['kind'])->toBe('query')
        ->and($label['type'])->toBe('string')
        ->and($label['attributes'] ?? null)->toBe([Tag::class])
        ->and($label['nullable'] ?? null)->toBeTrue()
        // An ordinary parameter's plan is byte-identical to the one compiled before the two keys existed.
        ->and($page['kind'])->toBe('query')
        ->and($page)->not->toHaveKey('attributes')
        ->and($page)->not->toHaveKey('nullable');
});
