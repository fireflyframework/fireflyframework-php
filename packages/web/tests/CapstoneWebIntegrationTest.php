<?php

declare(strict_types=1);

use Firefly\Web\Tests\Support\WebCapstoneTestCase;
use Symfony\Component\HttpFoundation\Response;

uses(WebCapstoneTestCase::class);

it('binds path + query + header and negotiates an array return to JSON', function () {
    /** @var WebCapstoneTestCase $this */
    $this->get('/balances/7')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['id' => 7, 'amount' => '100.00']);
});

it('validates a #[Valid] body and returns 201 on success', function () {
    /** @var WebCapstoneTestCase $this */
    $this->postJson('/balances', ['iban' => 'GB82WEST12345698765432', 'owner' => 'Ada'])
        ->assertStatus(201)
        ->assertJsonPath('owner', 'Ada');
});

it('renders an invalid #[Valid] body as a 422 RFC-7807 payload', function () {
    /** @var WebCapstoneTestCase $this */
    $response = $this->postJson('/balances', ['iban' => 'nope', 'owner' => '']);

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('category', 'validation')
        // The per-field error for `iban` is present in the RFC-7807 `errors` list (framework-native,
        // type-clean equivalent of plucking 'field' from the errors array).
        ->assertJsonFragment(['field' => 'iban']);
});

it('renders a thrown ResourceNotFoundException as 404 problem+json', function () {
    /** @var WebCapstoneTestCase $this */
    // No handler anywhere (AccountAdvice is NOT in the scanned Advice subdir), so this falls through to the
    // global RFC-7807 renderer.
    $this->getJson('/balances/404')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
        ->assertJsonPath('title', 'Not Found');
});

it('runs a controller-LOCAL #[ExceptionHandler] and renders it at the exception status', function () {
    /** @var WebCapstoneTestCase $this */
    // ConflictController throws CustomBusinessException (httpStatus 409); its own #[ExceptionHandler] returns a
    // custom body. The result renders at 409 (the EXCEPTION status), not the route's default 200 — exercising
    // the matched-handler status correction in ControllerDispatcher.
    $this->getJson('/errors/conflict')
        ->assertStatus(409)
        ->assertJsonPath('handled', 'local-conflict')
        ->assertJsonPath('code', 'CUSTOM_BUSINESS');
});

it('falls back to a global #[ControllerAdvice] #[ExceptionHandler] when no local handler matches', function () {
    /** @var WebCapstoneTestCase $this */
    // AnotherException has no local handler on ConflictController; the global CapstoneAdvice handles it.
    $this->getJson('/errors/another')
        ->assertJsonPath('handled', 'global-another');
});

it('runs the WebFilter chain in order (correlation id echoed + user filters ordered by #[Order])', function () {
    /** @var WebCapstoneTestCase $this */
    // X-Correlation-Id proves the framework CorrelationIdFilter ran. X-Filter-Trail === 'BA' proves the two
    // user filters ran AFTER the framework filters, sorted by #[Order] (A=10 outer, B=20 inner): post-processing
    // unwinds inner-first, so B appends before A. Reversing the sort would produce 'AB' and fail this line.
    $this->get('/balances/7')
        ->assertHeader('X-Correlation-Id')
        ->assertHeader('X-Filter-Trail', 'BA');
});

it('renders a generic Throwable as problem+json ONLY when the request expects JSON (expectsJson gate)', function () {
    /** @var WebCapstoneTestCase $this */
    // GET /boom/generic throws a plain \RuntimeException (NOT a FireflyException). The RFC-7807 renderable is
    // gated on `$e instanceof FireflyException || $request->expectsJson()`, so a generic error becomes
    // problem+json for a JSON client and otherwise falls through to Laravel's default handler.
    $this->getJson('/boom/generic')
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json');

    // Same route, non-JSON Accept: the generic Throwable must NOT be rendered as problem+json. Dropping the
    // expectsJson() gate (rendering generic Throwables unconditionally) would make this branch wrongly return
    // problem+json and fail the assertion below.
    $html = $this->get('/boom/generic', ['Accept' => 'text/html']);

    /** @var Response $base */
    $base = $html->baseResponse;
    expect((string) $base->headers->get('Content-Type'))->not->toContain('application/problem+json');
});
