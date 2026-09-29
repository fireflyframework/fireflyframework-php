<?php

declare(strict_types=1);

use Firefly\Admin\Route\RouteBinding;
use Firefly\Admin\Route\RouteBodyNode;
use Firefly\Admin\Route\RouteInspector;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolver;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolvers;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/** @return array{name: string, kind: string, key: string, type: string|null, required: bool, default: mixed, valid: bool, properties: list<string>} */
function detailBinding(string $name, string $kind, ?string $type = 'string', bool $required = true): array
{
    return ['name' => $name, 'kind' => $kind, 'key' => $name, 'type' => $type, 'required' => $required, 'default' => null, 'valid' => false, 'properties' => []];
}

it('keeps signature positions while separating resolver claims and services without resolving either', function () {
    $container = new Container;
    $resolvers = new HandlerMethodArgumentResolvers;
    $resolver = new class implements HandlerMethodArgumentResolver
    {
        public int $claims = 0;

        public function supports(array $binding): bool
        {
            $this->claims++;

            return $binding['name'] === 'principal';
        }

        public function resolve(array $binding, Request $request): mixed
        {
            throw new RuntimeException('Must never resolve a route argument in admin');
        }
    };
    $resolvers->add($resolver);
    $container->instance(HandlerMethodArgumentResolvers::class, $resolvers);
    $container->instance(RouteManifest::class, new RouteManifest([
        new RouteDescriptor('GET', '/orders/{id}', 'App\\Orders', 'show', 200, null, [
            detailBinding('id', 'path', 'int'), detailBinding('principal', 'query', 'int'), detailBinding('repository', 'service', 'MissingService'), detailBinding('limit', 'query', 'int', false),
        ]),
    ]));
    $detail = (new RouteInspector($container))->detail('GET /orders/{id}');
    if ($detail === null) {
        throw new RuntimeException('Expected a route detail');
    }
    expect($detail)->not->toBeNull()
        ->and(array_column($detail->arguments(), 'position'))->toBe([1, 2, 3, 4])
        ->and(array_column($detail->caller, 'position'))->toBe([1, 4])
        ->and(array_column($detail->injected, 'position'))->toBe([2, 3])
        ->and($detail->injected[0]->resolver)->toBe($resolver::class)
        ->and($resolver->claims)->toBe(4)
        ->and(array_column($detail->failures, 'binding'))->not->toContain('principal', 'repository');
});

it('uses the last duplicate and compares controller siblings without merging their bindings', function () {
    $container = new Container;
    $first = new RouteDescriptor('GET', '/orders', 'App\\Old', 'index', 200, 'old', []);
    $last = new RouteDescriptor('GET', '/orders', 'App\\Orders', 'index', 202, 'new', []);
    $sibling = new RouteDescriptor('POST', '/orders', 'App\\Orders', 'create', 201, null, [detailBinding('body', 'body')]);
    $container->instance(RouteManifest::class, new RouteManifest([$first, $last, $sibling]));
    $detail = (new RouteInspector($container))->detail('GET /orders');
    if ($detail === null) {
        throw new RuntimeException('Expected a route detail');
    }
    expect($detail->route)->toBe($last)->and($detail->registrations)->toBe([$first, $last])
        ->and($detail->siblings)->toBe([$sibling])
        ->and((new RouteInspector($container))->detail('GET /missing'))->toBeNull();
});

it('derives failures from the actual binding branch including upload arrays and excluding required bodies', function () {
    $container = new Container;
    $container->instance(RouteManifest::class, new RouteManifest([
        new RouteDescriptor('POST', '/orders/{id}', 'App\\Orders', 'create', 201, null, [
            [...detailBinding('id', 'path', 'int'), 'pattern' => '[0-9]+', 'notFoundCode' => 'ORDER_MISSING'],
            detailBinding('trace', 'header', 'bool'), detailBinding('upload', 'file'),
            [...detailBinding('body', 'body', stdClass::class), 'valid' => true],
            detailBinding('arrayBody', 'body', null),
        ]),
    ]));
    $detail = (new RouteInspector($container))->detail('POST /orders/{id}');
    if ($detail === null) {
        throw new RuntimeException('Expected a route detail');
    }
    $codes = fn (string $name) => array_column(array_filter($detail->failures, fn (array $f) => $f['binding'] === $name), 'code');
    expect($codes('id'))->toContain('ORDER_MISSING', 'MISSING_PARAMETER', 'TYPE_CONVERSION_ERROR')
        ->and($codes('upload'))->toContain('INVALID_UPLOAD', 'TYPE_CONVERSION_ERROR', 'MISSING_PARAMETER')
        ->and($codes('body'))->toContain('MALFORMED_BODY', 'INVALID_REQUEST', 'UNBINDABLE_BODY', (new ValidationException)->errorCode())->not->toContain('MISSING_PARAMETER')
        ->and($codes('arrayBody'))->not->toContain('MISSING_PARAMETER', 'UNBINDABLE_BODY', (new ValidationException)->errorCode())
        ->and($detail->caller[0]->notFoundMessage)->toBe('That resource does not exist.');
});

it('bounds recursive DTO trees and prefers compiled shapes to legacy property names', function () {
    $binding = new RouteBinding(1, [...detailBinding('body', 'body', 'Tree'), 'properties' => ['stale'], 'dtos' => [
        'Tree' => ['value' => ['class' => null, 'list' => false], 'children' => ['class' => 'Tree', 'list' => true]],
    ]], null);
    $tree = RouteBodyNode::forBinding($binding);
    expect(array_column($tree, 'name'))->toBe(['value', 'children'])
        ->and($tree[1]->note)->toBe('Recursive reference')->and($tree[1]->children)->toBe([]);
});

it('reads only route metadata with matching normalized URI method and domain', function () {
    $container = new Container;
    $router = new Router(new Dispatcher, $container);
    $router->get('/orders/{id}', fn () => 'never')->name('effective')->middleware('web')->where('id', '[0-9]+');
    $router->get('/', fn () => 'never')->name('root');
    $router->get('/orders/{id}', ['domain' => 'other.test', 'uses' => fn () => 'never'])->name('other');
    $container->instance('router', $router);
    $inspector = new RouteInspector($container);
    $route = new RouteDescriptor('GET', '/orders/{id}', 'App\\Orders', 'show', 200, null, []);
    expect($inspector->metadata($route))->toMatchArray(['name' => 'effective', 'uri' => 'orders/{id}', 'domain' => null, 'middleware' => ['web'], 'patterns' => ['id' => '[0-9]+']])
        ->and($inspector->metadata(new RouteDescriptor('GET', '/', 'App\\Home', 'index', 200, null, []))['name'] ?? null)->toBe('root');
});

it('reports compiled advice in chain order without instantiating interceptors', function () {
    $container = new Container;
    $container->bind('live.interceptor', fn () => throw new RuntimeException('Do not instantiate'));
    $container->instance(ProxyPlan::class, new ProxyPlan([
        stdClass::class => ['proxyClass' => 'Unused', 'advice' => [
            'meter' => ['id' => 'meter', 'interceptor' => 'live.interceptor', 'descriptor' => 'Unused', 'order' => 50],
            'optional' => ['id' => 'optional', 'interceptor' => 'optional.interceptor', 'descriptor' => 'Unused', 'order' => 100, 'inert' => true],
            'tx' => ['id' => 'tx', 'interceptor' => 'missing.interceptor', 'descriptor' => 'Unused', 'order' => 1000],
        ], 'methods' => ['index' => [['advice' => 'meter', 'row' => ['name' => 'calls']], ['advice' => 'optional', 'row' => []], ['advice' => 'tx', 'row' => ['readOnly' => true]]]]],
    ]));
    $route = new RouteDescriptor('GET', '/', stdClass::class, 'index', 200, null, []);
    $advice = (new RouteInspector($container))->advice($route);
    expect(array_column($advice, 'id'))->toBe(['meter', 'optional', 'tx'])
        ->and(array_column($advice, 'state'))->toBe(['LIVE', 'INERT', 'UNBOUND']);
});

it('derives a pattern failure sentence from the PHP name rather than the wire key', function () {
    $binding = new RouteBinding(1, [...detailBinding('orderId', 'path'), 'key' => 'id', 'pattern' => '[0-9]+'], null);
    expect($binding->notFoundMessage)->toBe(ArgumentResolver::notFoundSentence('orderId'));
});

it('distinguishes zero null false and empty defaults in the compiled contract', function (mixed $default, string $label) {
    $binding = new RouteBinding(1, [...detailBinding('limit', 'query', 'int', false), 'default' => $default], null);
    expect($binding->defaultLabel())->toBe($label);
})->with([[0, '0'], [null, 'null'], [false, 'false'], ['', '""'], [[], '[]']]);
