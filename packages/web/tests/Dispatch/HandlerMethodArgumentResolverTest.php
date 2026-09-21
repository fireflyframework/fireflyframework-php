<?php

declare(strict_types=1);

use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolver;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolvers;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\Resolver\Tag;
use Firefly\Web\Tests\Fixtures\Resolver\TagController;
use Firefly\Web\WebServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

it('lets a registered resolver claim a binding before the built-in kinds', function () {
    $resolvers = new HandlerMethodArgumentResolvers;
    $resolvers->add(new class implements HandlerMethodArgumentResolver
    {
        /** @param array<string, mixed> $binding */
        public function supports(array $binding): bool
        {
            $attributes = $binding['attributes'] ?? [];

            return is_array($attributes) && in_array(Tag::class, $attributes, true);
        }

        /** @param array<string, mixed> $binding */
        public function resolve(array $binding, Request $request): mixed
        {
            $name = $binding['name'] ?? null;

            return is_string($name) ? 'tagged:'.$name : null;
        }
    });

    // The app boots eagerly (RouteWiringPass builds the dispatcher, and with it the ArgumentResolver), so the
    // registry is joined, not replaced — exactly how SecurityWiringPass registers its resolver at boot.
    $app = fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);
    /** @var HandlerMethodArgumentResolvers $registry */
    $registry = $app->make(HandlerMethodArgumentResolvers::class);
    foreach ($resolvers->all() as $resolver) {
        $registry->add($resolver);
    }

    $routes = (new RouteScanner)->scan(['Firefly\\Web\\Tests\\Fixtures\\Resolver\\' => __DIR__.'/../Fixtures/Resolver']);
    $route = array_values(array_filter($routes, static fn ($r): bool => $r->controllerClass === TagController::class))[0];

    /** @var ArgumentResolver $resolver */
    $resolver = $app->make(ArgumentResolver::class);
    $args = $resolver->resolve($route->bindings, Request::create('/tags', 'GET'), new Container);

    expect($args[0])->toBe('tagged:tag')
        ->and($args[1])->toBeNull()
        ->and($registry->resolverFor(['name' => 'x', 'kind' => 'query', 'key' => 'x', 'type' => null, 'required' => false, 'default' => null, 'valid' => false, 'properties' => []]))->toBeNull();
});
