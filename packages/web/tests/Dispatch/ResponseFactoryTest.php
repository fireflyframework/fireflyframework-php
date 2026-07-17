<?php

declare(strict_types=1);

use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

function descriptor(int $status): RouteDescriptor
{
    return new RouteDescriptor('GET', '/x', 'C', 'm', $status, null, []);
}

function jsonResponseFactory(): ResponseFactory
{
    return new ResponseFactory(new MessageConverterRegistry([new JsonMessageConverter]));
}

it('negotiates an array return to JSON with the descriptor status', function () {
    $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

    $response = jsonResponseFactory()->make(['ok' => true], descriptor(201), $request);

    expect($response->getStatusCode())->toBe(201)
        ->and($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->getContent())->toBe('{"ok":true}');
});

it('passes an existing Response through untouched', function () {
    $existing = new Response('raw', 418);

    expect(jsonResponseFactory()->make($existing, descriptor(200), Request::create('/x')))->toBe($existing);
});

it('renders a Responsable via its own toResponse', function () {
    $responsable = new class implements Responsable
    {
        public function toResponse($request): Response
        {
            return new Response('via-responsable', 202);
        }
    };

    $response = jsonResponseFactory()->make($responsable, descriptor(200), Request::create('/x'));

    expect($response->getContent())->toBe('via-responsable')
        ->and($response->getStatusCode())->toBe(202);
});

it('passes an existing JsonResponse through untouched', function () {
    $existing = new JsonResponse(['already' => 'built'], 207);

    expect(jsonResponseFactory()->make($existing, descriptor(200), Request::create('/x')))->toBe($existing);
});

it('lets an explicit status override win over the descriptor status', function () {
    $request = Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

    $response = jsonResponseFactory()->make(['ok' => true], descriptor(200), $request, 503);

    expect($response->getStatusCode())->toBe(503);
});
