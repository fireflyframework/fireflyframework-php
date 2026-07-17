<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\IlluminateValidator;
use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Exception\InvalidRequestException;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Tests\Fixtures\CreateAccountRequest;
use Firefly\Web\Tests\Fixtures\PartialBodyRequest;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * @param  class-string  ...$dtoClasses
 */
function resolverFor(string ...$dtoClasses): ArgumentResolver
{
    $manifest = ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray(array_values($dtoClasses)));
    $beanValidator = new BeanValidator(
        new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))),
        $manifest,
    );

    return new ArgumentResolver(
        new MessageConverterRegistry([new JsonMessageConverter]),
        $beanValidator,
    );
}

/**
 * Binds a route so $request->route()->parameter() works.
 *
 * @param  array<string, string>  $params
 */
function requestWithRoute(Request $request, string $uri, array $params): Request
{
    $route = new Route(['GET'], $uri, []);
    $route->bind($request);
    foreach ($params as $key => $value) {
        $route->setParameter($key, $value);
    }
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('binds path, query and header params and coerces the path scalar', function () {
    $request = requestWithRoute(Request::create('/accounts/42?view=full', 'GET', server: ['HTTP_X_TRACE' => 'abc']), '/accounts/{id}', ['id' => '42']);

    $args = resolverFor()->resolve([
        ['name' => 'id', 'kind' => 'path', 'key' => 'id', 'type' => 'int', 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
        ['name' => 'view', 'kind' => 'query', 'key' => 'view', 'type' => 'string', 'required' => false, 'default' => 'summary', 'valid' => false, 'properties' => []],
        ['name' => 'trace', 'kind' => 'header', 'key' => 'X-Trace', 'type' => 'string', 'required' => false, 'default' => null, 'valid' => false, 'properties' => []],
    ], $request, new Container);

    expect($args)->toBe([42, 'full', 'abc']);
});

it('applies the query default when the parameter is absent', function () {
    $request = requestWithRoute(Request::create('/accounts/1', 'GET'), '/accounts/{id}', ['id' => '1']);

    $args = resolverFor()->resolve([
        ['name' => 'view', 'kind' => 'query', 'key' => 'view', 'type' => 'string', 'required' => false, 'default' => 'summary', 'valid' => false, 'properties' => []],
    ], $request, new Container);

    expect($args)->toBe(['summary']);
});

it('throws MISSING_PARAMETER (400) for an absent required query param', function () {
    $request = requestWithRoute(Request::create('/accounts/1', 'GET'), '/accounts/{id}', ['id' => '1']);

    try {
        resolverFor()->resolve([
            ['name' => 'page', 'kind' => 'query', 'key' => 'page', 'type' => 'int', 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)->and($e->errorCode())->toBe('MISSING_PARAMETER');
    }
});

it('throws TYPE_CONVERSION_ERROR (400) for an uncoercible scalar', function () {
    $request = requestWithRoute(Request::create('/accounts/not-an-int', 'GET'), '/accounts/{id}', ['id' => 'not-an-int']);

    try {
        resolverFor()->resolve([
            ['name' => 'id', 'kind' => 'path', 'key' => 'id', 'type' => 'int', 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->errorCode())->toBe('TYPE_CONVERSION_ERROR');
    }
});

it('validates a #[Valid] body before hydrating the DTO, and hydrates on success', function () {
    $request = Request::create('/accounts', 'POST', content: json_encode(['iban' => 'GB82WEST12345698765432', 'owner' => 'Ada'], JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');

    $args = resolverFor(CreateAccountRequest::class)->resolve([
        ['name' => 'body', 'kind' => 'body', 'key' => '', 'type' => CreateAccountRequest::class, 'required' => true, 'default' => null, 'valid' => true, 'properties' => ['iban', 'owner']],
    ], $request, new Container);

    $dto = $args[0];
    expect($dto)->toBeInstanceOf(CreateAccountRequest::class);
    if (! $dto instanceof CreateAccountRequest) {
        throw new RuntimeException('Expected a CreateAccountRequest.');
    }
    expect($dto->owner)->toBe('Ada');
});

it('hydrates an unconstrained DTO property from the raw body, not only the validated subset', function () {
    $request = Request::create('/things', 'POST', content: json_encode(['name' => 'Ada', 'nickname' => 'Countess'], JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');

    // PartialBodyRequest has one constrained prop (#[NotBlank] $name) and one UNCONSTRAINED prop ($nickname,
    // default null). BeanValidator::validate() returns validated() = {name} only; hydrating from that subset
    // would drop $nickname to its default. The DTO is hydrated from the RAW body instead, so $nickname survives.
    $args = resolverFor(PartialBodyRequest::class)->resolve([
        ['name' => 'body', 'kind' => 'body', 'key' => '', 'type' => PartialBodyRequest::class, 'required' => true, 'default' => null, 'valid' => true, 'properties' => ['name', 'nickname']],
    ], $request, new Container);

    $dto = $args[0];
    expect($dto)->toBeInstanceOf(PartialBodyRequest::class);
    if (! $dto instanceof PartialBodyRequest) {
        throw new RuntimeException('Expected a PartialBodyRequest.');
    }
    expect($dto->name)->toBe('Ada')
        ->and($dto->nickname)->toBe('Countess');
});

it('throws a ValidationException (=> 422) for an invalid #[Valid] body', function () {
    $request = Request::create('/accounts', 'POST', content: json_encode(['iban' => 'nope', 'owner' => ''], JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');

    resolverFor(CreateAccountRequest::class)->resolve([
        ['name' => 'body', 'kind' => 'body', 'key' => '', 'type' => CreateAccountRequest::class, 'required' => true, 'default' => null, 'valid' => true, 'properties' => ['iban', 'owner']],
    ], $request, new Container);
})->throws(ValidationException::class);

it('throws MALFORMED_BODY (400), not an uncaught JsonException, for an empty request body', function () {
    $request = Request::create('/accounts', 'POST', content: '');
    $request->headers->set('Content-Type', 'application/json');

    try {
        resolverFor(CreateAccountRequest::class)->resolve([
            ['name' => 'body', 'kind' => 'body', 'key' => '', 'type' => CreateAccountRequest::class, 'required' => true, 'default' => null, 'valid' => true, 'properties' => ['iban', 'owner']],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)->and($e->errorCode())->toBe('MALFORMED_BODY');
    }
});

it('throws MALFORMED_BODY (400), not an uncaught JsonException, for a syntactically-broken request body', function () {
    $request = Request::create('/accounts', 'POST', content: '{not json');
    $request->headers->set('Content-Type', 'application/json');

    try {
        resolverFor(CreateAccountRequest::class)->resolve([
            ['name' => 'body', 'kind' => 'body', 'key' => '', 'type' => CreateAccountRequest::class, 'required' => true, 'default' => null, 'valid' => true, 'properties' => ['iban', 'owner']],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)->and($e->errorCode())->toBe('MALFORMED_BODY');
    }
});

it('still throws INVALID_REQUEST (400) via the is_array guard for valid-but-non-object JSON', function () {
    $request = Request::create('/accounts', 'POST', content: '42');
    $request->headers->set('Content-Type', 'application/json');

    try {
        resolverFor(CreateAccountRequest::class)->resolve([
            ['name' => 'body', 'kind' => 'body', 'key' => '', 'type' => CreateAccountRequest::class, 'required' => true, 'default' => null, 'valid' => true, 'properties' => ['iban', 'owner']],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)->and($e->errorCode())->toBe('INVALID_REQUEST');
    }
});
