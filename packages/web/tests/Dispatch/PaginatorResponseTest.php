<?php

declare(strict_types=1);

use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Orchestra\Testbench\TestCase;

/*
 | A Laravel paginator is Htmlable — AbstractPaginator::toHtml() renders its pagination LINKS through the view
 | layer — and it is also Arrayable and JsonSerializable. ResponseFactory's HTML arm used to run first, so a
 | #[RestController] that returned `->paginate()` answered with link markup (or, with no view factory bound, a
 | TypeError out of Paginator::viewFactory()) instead of its data. Laravel's own Response::shouldBeJson() decides
 | the other way round: a value that knows its JSON shape is data, whatever else it can also render.
 |
 | A full application rather than a bare unit test because LengthAwarePaginator::toArray() builds its `links`
 | labels through the translator — the paginator only has a JSON shape inside an app that has one.
 */
uses(TestCase::class);

it('writes a returned paginator as its JSON envelope rather than rendering its links', function () {
    $factory = new ResponseFactory(new MessageConverterRegistry([new JsonMessageConverter]));
    $route = new RouteDescriptor('GET', '/orders', 'OrderController', 'index', 200, null, []);
    $request = Request::create('/orders', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $paginator = new LengthAwarePaginator([['id' => 1], ['id' => 2]], total: 7, perPage: 2, currentPage: 1);

    $response = $factory->make($paginator, $route, $request);

    /** @var array{data: list<array{id: int}>, total: int, per_page: int, last_page: int} $body */
    $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($body['data'])->toBe([['id' => 1], ['id' => 2]])
        ->and($body['total'])->toBe(7)
        ->and($body['per_page'])->toBe(2)
        ->and($body['last_page'])->toBe(4);
});
