<?php

declare(strict_types=1);

namespace Firefly\Admin\Route;

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolvers;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;

/** Reads the booted manifests; never resolves a handler, service argument, or request value. */
final readonly class RouteInspector
{
    public function __construct(private Container $container) {}

    public static function keyOf(RouteDescriptor $route): string
    {
        return $route->httpMethod.' '.$route->path;
    }

    public function detail(string $key): ?RouteDetail
    {
        if (! $this->container->bound(RouteManifest::class)) {
            return null;
        }
        $routes = $this->container->make(RouteManifest::class)->all();
        $matches = array_values(array_filter($routes, static fn (RouteDescriptor $route): bool => self::keyOf($route) === $key));
        if ($matches === []) {
            return null;
        }
        // Laravel registers in manifest order: the final registration for a verb/path replaces earlier ones.
        $route = $matches[array_key_last($matches)];
        $resolvers = $this->container->bound(HandlerMethodArgumentResolvers::class)
            ? $this->container->make(HandlerMethodArgumentResolvers::class) : null;
        $caller = $injected = $failures = [];
        foreach ($route->bindings as $position => $plan) {
            // supports() only. resolve() can read a security context, instantiate services, or throw.
            $resolver = $resolvers?->resolverFor($plan);
            $binding = new RouteBinding($position + 1, $plan, $resolver === null ? null : $resolver::class);
            if ($binding->supplied()) {
                $injected[] = $binding;
            } else {
                $caller[] = $binding;
                array_push($failures, ...$this->failures($binding));
            }
        }

        return new RouteDetail($route, $matches, $caller, $injected, $failures, array_values(array_filter(
            $routes, static fn (RouteDescriptor $sibling): bool => $sibling->controllerClass === $route->controllerClass && self::keyOf($sibling) !== $key,
        )));
    }

    /** @return array{uri: string, name: string|null, domain: string|null, middleware: list<string>, patterns: array<string, string>}|null */
    public function metadata(RouteDescriptor $descriptor): ?array
    {
        $router = $this->container->bound('router') ? $this->container->make('router') : null;
        if (! $router instanceof Router) {
            return null;
        }
        $uri = $descriptor->path === '/' ? '/' : trim($descriptor->path, '/');
        foreach ($router->getRoutes()->getRoutes() as $route) {
            // Manifest routes have no domain. A domain-specific route with the same URI is a different route.
            if ($route->uri() === $uri && $route->getDomain() === null && in_array($descriptor->httpMethod, $route->methods(), true)) {
                $patterns = [];
                foreach ($route->wheres as $name => $pattern) {
                    if (is_string($name) && is_string($pattern)) {
                        $patterns[$name] = $pattern;
                    }
                }

                return ['uri' => $route->uri(), 'name' => $route->getName(), 'domain' => $route->getDomain(),
                    'middleware' => array_values(array_filter($route->middleware(), is_string(...))), 'patterns' => $patterns];
            }
        }

        return null;
    }

    /** @return list<array{id: string, order: int, interceptor: string, state: string, contract: string}> */
    public function advice(RouteDescriptor $route): array
    {
        if (! $this->container->bound(ProxyPlan::class)) {
            return [];
        }
        $plan = $this->container->make(ProxyPlan::class);
        $kinds = $plan->adviceFor($route->controllerClass);
        $rows = [];
        foreach ($plan->methodsFor($route->controllerClass)[$route->methodName] ?? [] as $link) {
            $kind = $kinds[$link['advice']] ?? null;
            if ($kind === null) {
                continue;
            }
            // Read raw rows across the existing Admin→Data edge; descriptor strings are data, never imports.
            // An unbound interceptor is inert ONLY if that advice explicitly permits it; otherwise boot fails.
            $inert = $kind->inertWhenUnbound;
            $rows[] = ['id' => $link['advice'], 'order' => $kind->order, 'interceptor' => $kind->interceptorClass,
                'state' => $this->container->bound($kind->interceptorClass) ? 'LIVE' : ($inert ? 'INERT' : 'UNBOUND'),
                'contract' => json_encode($link['row'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'];
        }

        return $rows;
    }

    /** @return list<array{status: int, code: string, binding: string, reason: string}> */
    private function failures(RouteBinding $binding): array
    {
        $plan = $binding->plan;
        $kind = $plan['kind'];
        $failures = [];
        $add = static function (int $status, string $code, string $reason) use (&$failures, $plan): void {
            $failures[] = ['status' => $status, 'code' => $code, 'binding' => $plan['name'], 'reason' => $reason];
        };
        if ($plan['required'] && in_array($kind, ['path', 'query', 'header', 'file'], true)) {
            $add(400, 'MISSING_PARAMETER', 'Required '.$kind.' value is absent.');
        }
        if (($kind === 'file') || (in_array($kind, ['path', 'query', 'header'], true) && in_array($plan['type'], ['int', 'float', 'bool'], true))) {
            $add(400, 'TYPE_CONVERSION_ERROR', $kind === 'file' ? 'Multiple uploads were sent to a single-file argument.' : 'The value cannot be converted to '.$plan['type'].'.');
        }
        if ($kind === 'path' && isset($plan['pattern'])) {
            $add(404, $plan['notFoundCode'] ?? 'RESOURCE_NOT_FOUND', $binding->notFoundMessage ?? 'Path pattern did not match.');
        }
        if ($kind === 'file') {
            $add(400, 'INVALID_UPLOAD', 'The upload did not complete.');
        }
        if ($kind === 'body') {
            $add(400, 'MALFORMED_BODY', 'The body reader rejects malformed JSON.');
            $add(400, 'INVALID_REQUEST', 'Unsupported Content-Type or a decoded value that is not an array.');
            if ($plan['type'] !== null && class_exists($plan['type'])) {
                $add(400, 'UNBINDABLE_BODY', 'The decoded body cannot construct the DTO.');
            }
            if ($plan['valid'] && $plan['type'] !== null) {
                $add(422, 'VALIDATION_FAILED', 'Bean validation rejects the body before hydration.');
            }
        }

        return $failures;
    }
}
