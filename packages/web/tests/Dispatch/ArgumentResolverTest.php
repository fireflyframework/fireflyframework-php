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
use Firefly\Web\Http\UploadedFile;
use Firefly\Web\Tests\Fixtures\AddressPayload;
use Firefly\Web\Tests\Fixtures\CreateAccountRequest;
use Firefly\Web\Tests\Fixtures\Currency;
use Firefly\Web\Tests\Fixtures\GeoPoint;
use Firefly\Web\Tests\Fixtures\MoneyTransferRequest;
use Firefly\Web\Tests\Fixtures\NodeRequest;
use Firefly\Web\Tests\Fixtures\PartialBodyRequest;
use Firefly\Web\Tests\Fixtures\PricedRequest;
use Firefly\Web\Tests\Fixtures\TransferLine;
use Firefly\Web\Tests\Fixtures\UnbindableRequest;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile as IlluminateUploadedFile;
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

/**
 * The compiled shape table for the MoneyTransferRequest graph — one row per reachable DTO class, each row
 * mapping a constructor parameter to the class it is built from (null for a builtin) and whether the payload
 * holds a LIST of that class. This is the `dtos` key RouteScanner must learn to emit; hand-built here so the
 * resolver's half of the fix is provable on its own.
 *
 * @return array<string, array<string, array{class: string|null, list: bool}>>
 */
function transferShapes(): array
{
    return [
        MoneyTransferRequest::class => [
            'amount' => ['class' => null, 'list' => false],
            'beneficiary' => ['class' => AddressPayload::class, 'list' => false],
            'lines' => ['class' => TransferLine::class, 'list' => true],
            'reference' => ['class' => null, 'list' => false],
        ],
        AddressPayload::class => [
            'street' => ['class' => null, 'list' => false],
            'postcode' => ['class' => null, 'list' => false],
            'geo' => ['class' => GeoPoint::class, 'list' => false],
        ],
        GeoPoint::class => [
            'lat' => ['class' => null, 'list' => false],
            'lon' => ['class' => null, 'list' => false],
        ],
        TransferLine::class => [
            'reference' => ['class' => null, 'list' => false],
            'cents' => ['class' => null, 'list' => false],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, array<string, array{class: string|null, list: bool}>>  $dtos
 * @param  list<string>  $properties
 */
function resolveBody(string $type, array $body, array $dtos = [], array $properties = [], bool $valid = false): mixed
{
    $request = Request::create('/x', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');

    $binding = ['name' => 'body', 'kind' => 'body', 'key' => '', 'type' => $type, 'required' => true, 'default' => null, 'valid' => $valid, 'properties' => $properties];
    if ($dtos !== []) {
        $binding['dtos'] = $dtos;
    }

    /** @var class-string $type */
    return resolverFor($type)->resolve([$binding], $request, new Container)[0];
}

it('hydrates a nested DTO from its sub-array instead of passing the raw array to the constructor', function () {
    $dto = resolveBody(MoneyTransferRequest::class, [
        'amount' => 250,
        'beneficiary' => ['street' => 'Calle Mayor 1', 'postcode' => '28013'],
    ], transferShapes());

    expect($dto)->toBeInstanceOf(MoneyTransferRequest::class);
    if (! $dto instanceof MoneyTransferRequest) {
        throw new RuntimeException('Expected a MoneyTransferRequest.');
    }
    expect($dto->beneficiary)->toBeInstanceOf(AddressPayload::class)
        ->and($dto->beneficiary->postcode)->toBe('28013')
        ->and($dto->amount)->toBe(250)
        ->and($dto->lines)->toBe([])
        ->and($dto->reference)->toBeNull();
});

it('recurses to arbitrary depth — the third level is hydrated as readily as the first', function () {
    $dto = resolveBody(MoneyTransferRequest::class, [
        'amount' => 1,
        'beneficiary' => [
            'street' => 'Calle Mayor 1',
            'postcode' => '28013',
            'geo' => ['lat' => 40.415, 'lon' => -3.707],
        ],
    ], transferShapes());

    if (! $dto instanceof MoneyTransferRequest) {
        throw new RuntimeException('Expected a MoneyTransferRequest.');
    }
    expect($dto->beneficiary->geo)->toBeInstanceOf(GeoPoint::class)
        ->and($dto->beneficiary->geo?->lat)->toBe(40.415);
});

it('hydrates a LIST of DTOs, preserving order', function () {
    $dto = resolveBody(MoneyTransferRequest::class, [
        'amount' => 3,
        'beneficiary' => ['street' => 'A', 'postcode' => 'B'],
        'lines' => [
            ['reference' => 'INV-1', 'cents' => 100],
            ['reference' => 'INV-2', 'cents' => 250],
        ],
    ], transferShapes());

    if (! $dto instanceof MoneyTransferRequest) {
        throw new RuntimeException('Expected a MoneyTransferRequest.');
    }
    expect($dto->lines)->toHaveCount(2)
        ->and($dto->lines[0])->toBeInstanceOf(TransferLine::class)
        ->and($dto->lines[0]->reference)->toBe('INV-1')
        ->and($dto->lines[1]->cents)->toBe(250);
});

it('leaves an explicit null on a nested property as null rather than building an empty DTO', function () {
    $dto = resolveBody(MoneyTransferRequest::class, [
        'amount' => 1,
        'beneficiary' => ['street' => 'A', 'postcode' => 'B', 'geo' => null],
    ], transferShapes());

    if (! $dto instanceof MoneyTransferRequest) {
        throw new RuntimeException('Expected a MoneyTransferRequest.');
    }
    expect($dto->beneficiary->geo)->toBeNull();
});

it('descends a SELF-REFERENTIAL DTO as deep as the payload goes, unlike the one-level #[Valid] cascade', function () {
    // ConstraintScanner stops expanding NodeRequest after one level (its ancestor guard); hydration is keyed
    // by class, so the same single table row serves every level of the payload.
    $shapes = [NodeRequest::class => [
        'label' => ['class' => null, 'list' => false],
        'child' => ['class' => NodeRequest::class, 'list' => false],
    ]];

    $dto = resolveBody(NodeRequest::class, [
        'label' => 'root',
        'child' => ['label' => 'a', 'child' => ['label' => 'b', 'child' => ['label' => 'c']]],
    ], $shapes);

    if (! $dto instanceof NodeRequest) {
        throw new RuntimeException('Expected a NodeRequest.');
    }
    expect($dto->child?->child?->child?->label)->toBe('c')
        ->and($dto->child?->child?->child?->child)->toBeNull();
});

it('throws UNBINDABLE_BODY (400) — not a TypeError 500 — for a nested property that is not an object', function () {
    try {
        resolveBody(MoneyTransferRequest::class, [
            'amount' => 1,
            'beneficiary' => 'Calle Mayor 1',
        ], transferShapes());
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)
            ->and($e->errorCode())->toBe('UNBINDABLE_BODY')
            ->and($e->getMessage())->toContain('beneficiary');
    }
});

it('throws UNBINDABLE_BODY (400) and names the OFFENDING ELEMENT when a list holds a scalar', function () {
    try {
        resolveBody(MoneyTransferRequest::class, [
            'amount' => 1,
            'beneficiary' => ['street' => 'A', 'postcode' => 'B'],
            'lines' => [['reference' => 'INV-1', 'cents' => 100], 'INV-2'],
        ], transferShapes());
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->errorCode())->toBe('UNBINDABLE_BODY')
            ->and($e->getMessage())->toContain('lines[1]');
    }
});

it('throws UNBINDABLE_BODY (400) when a list-typed property is not a list at all', function () {
    try {
        resolveBody(MoneyTransferRequest::class, [
            'amount' => 1,
            'beneficiary' => ['street' => 'A', 'postcode' => 'B'],
            'lines' => 'INV-1',
        ], transferShapes());
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->errorCode())->toBe('UNBINDABLE_BODY')
            ->and($e->getMessage())->toContain('lines');
    }
});

it('throws UNBINDABLE_BODY (400) for an INTERFACE-typed constructor parameter, which has no class to build', function () {
    $shapes = [UnbindableRequest::class => [
        'name' => ['class' => null, 'list' => false],
        'counter' => ['class' => Countable::class, 'list' => false],
    ]];

    try {
        resolveBody(UnbindableRequest::class, ['name' => 'Ada', 'counter' => ['n' => 1]], $shapes);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)
            ->and($e->errorCode())->toBe('UNBINDABLE_BODY')
            ->and($e->getMessage())->toContain('counter');
    }
});

it('throws UNBINDABLE_BODY (400) for a scalar the constructor refuses, instead of leaking the TypeError', function () {
    // "amount": "lots" reaches `int $amount` untouched — the DTO constructor is the arbiter for builtins, and
    // its TypeError is the one the resolver converts.
    try {
        resolveBody(MoneyTransferRequest::class, [
            'amount' => 'lots',
            'beneficiary' => ['street' => 'A', 'postcode' => 'B'],
        ], transferShapes());
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->errorCode())->toBe('UNBINDABLE_BODY')
            ->and($e->getMessage())->not->toContain('must be of type')
            ->and($e->getMessage())->not->toContain(DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR);
    }
});

it('falls back to a 400 (never a 500) on the LEGACY flat plan that cannot describe the nesting', function () {
    // No `dtos` row: `properties` alone says "the constructor takes $amount, $beneficiary, $lines,
    // $reference" and nothing about their types, so $beneficiary arrives as a raw array. That is the exact
    // shape RouteScanner still compiles today, and the exact request that used to render as a 500.
    try {
        resolveBody(
            MoneyTransferRequest::class,
            ['amount' => 1, 'beneficiary' => ['street' => 'A', 'postcode' => 'B']],
            [],
            ['amount', 'beneficiary', 'lines', 'reference'],
        );
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)->and($e->errorCode())->toBe('UNBINDABLE_BODY');
    }
});

it('keeps the compiled shape table authoritative over the flat property list when both are present', function () {
    $dto = resolveBody(
        MoneyTransferRequest::class,
        ['amount' => 7, 'beneficiary' => ['street' => 'A', 'postcode' => 'B']],
        transferShapes(),
        ['amount'],
    );

    if (! $dto instanceof MoneyTransferRequest) {
        throw new RuntimeException('Expected a MoneyTransferRequest.');
    }
    expect($dto->beneficiary->street)->toBe('A');
});

it('validates the nested payload BEFORE hydrating it, so a 422 still beats the 400', function () {
    try {
        resolveBody(MoneyTransferRequest::class, [
            'amount' => 1,
            'beneficiary' => ['street' => '', 'postcode' => ''],
        ], transferShapes(), valid: true);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->httpStatus())->toBe(422);
    }
});

it('throws MISSING_PARAMETER (400) for an absent REQUIRED header instead of a constructor TypeError', function () {
    $request = Request::create('/accounts', 'GET');

    try {
        resolverFor()->resolve([
            ['name' => 'tenant', 'kind' => 'header', 'key' => 'X-Tenant', 'type' => 'string', 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)->and($e->errorCode())->toBe('MISSING_PARAMETER');
    }
});

it('coerces a header to the parameter type, the same as a path or query binding', function () {
    $request = Request::create('/accounts', 'GET', server: ['HTTP_X_API_VERSION' => '3']);

    $args = resolverFor()->resolve([
        ['name' => 'version', 'kind' => 'header', 'key' => 'X-Api-Version', 'type' => 'int', 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
    ], $request, new Container);

    expect($args)->toBe([3]);
});

it('throws TYPE_CONVERSION_ERROR (400) for a header that will not coerce', function () {
    $request = Request::create('/accounts', 'GET', server: ['HTTP_X_API_VERSION' => 'three']);

    try {
        resolverFor()->resolve([
            ['name' => 'version', 'kind' => 'header', 'key' => 'X-Api-Version', 'type' => 'int', 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->errorCode())->toBe('TYPE_CONVERSION_ERROR');
    }
});

it('binds an uploaded file and returns null for an optional one that was not sent', function () {
    $path = tempnam(sys_get_temp_dir(), 'fw-upload');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary upload.');
    }
    file_put_contents($path, 'hello');

    try {
        $request = Request::create('/uploads', 'POST', files: [
            'avatar' => new IlluminateUploadedFile($path, 'avatar.txt', 'text/plain', null, true),
        ]);

        $args = resolverFor()->resolve([
            ['name' => 'avatar', 'kind' => 'file', 'key' => 'avatar', 'type' => UploadedFile::class, 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
            ['name' => 'banner', 'kind' => 'file', 'key' => 'banner', 'type' => UploadedFile::class, 'required' => false, 'default' => null, 'valid' => false, 'properties' => []],
        ], $request, new Container);

        expect($args[0])->toBeInstanceOf(UploadedFile::class)
            ->and($args[1])->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('throws MISSING_PARAMETER (400) for an absent REQUIRED uploaded file instead of a constructor TypeError', function () {
    $request = Request::create('/uploads', 'POST');

    try {
        resolverFor()->resolve([
            ['name' => 'avatar', 'kind' => 'file', 'key' => 'avatar', 'type' => UploadedFile::class, 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->httpStatus())->toBe(400)->and($e->errorCode())->toBe('MISSING_PARAMETER');
    }
});

it('throws TYPE_CONVERSION_ERROR (400) when a multi-file field is bound to a single-file parameter', function () {
    $path = tempnam(sys_get_temp_dir(), 'fw-upload');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary upload.');
    }
    file_put_contents($path, 'hello');

    try {
        $request = Request::create('/uploads', 'POST', files: [
            'avatar' => [new IlluminateUploadedFile($path, 'a.txt', 'text/plain', null, true)],
        ]);

        resolverFor()->resolve([
            ['name' => 'avatar', 'kind' => 'file', 'key' => 'avatar', 'type' => UploadedFile::class, 'required' => true, 'default' => null, 'valid' => false, 'properties' => []],
        ], $request, new Container);
        $this->fail('Expected InvalidRequestException');
    } catch (InvalidRequestException $e) {
        expect($e->errorCode())->toBe('TYPE_CONVERSION_ERROR');
    } finally {
        @unlink($path);
    }
});

it('refuses an ENUM-typed property with a 400 rather than letting "cannot instantiate enum" escape as a 500', function () {
    // class_exists() answers TRUE for an enum, so Currency reaches the same `new $class(...)` a nested DTO
    // does — and raises a plain Error, not a TypeError. Binding an enum from its BACKING VALUE is a separate
    // feature that needs the compiled plan to say "this property is an enum"; until then the contract this
    // locks in is only that neither spelling of the payload can produce a server error.
    $shapes = [PricedRequest::class => [
        'cents' => ['class' => null, 'list' => false],
        'currency' => ['class' => Currency::class, 'list' => false],
    ]];

    foreach ([['value' => 'EUR'], 'EUR'] as $currency) {
        try {
            resolveBody(PricedRequest::class, ['cents' => 100, 'currency' => $currency], $shapes);
            $this->fail('Expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            expect($e->httpStatus())->toBe(400)
                ->and($e->errorCode())->toBe('UNBINDABLE_BODY')
                ->and($e->getMessage())->toContain('currency');
        }
    }
});
