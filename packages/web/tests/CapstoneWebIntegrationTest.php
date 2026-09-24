<?php

declare(strict_types=1);

use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Tests\Fixtures\Filters\CarriedResponseFilter;
use Firefly\Web\Tests\Support\WebCapstoneTestCase;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
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
        ->assertJsonFragment(['field' => 'iban']);

    // Worded by the constraint, naming it, with the rejected value — the compiled-manifest capstone path.
    expect((array) $response->json('errors'))
        ->toContain(['field' => 'iban', 'message' => 'must be a valid IBAN', 'constraint' => 'Iban', 'rejectedValue' => 'nope'])
        ->toContain(['field' => 'owner', 'message' => 'must not be blank', 'constraint' => 'NotBlank', 'rejectedValue' => '']);
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

it('renders a generic Throwable as a page ONLY for a caller that NAMED text/html, and as problem+json otherwise', function () {
    /** @var WebCapstoneTestCase $this */
    // GET /boom/generic throws a plain \RuntimeException (NOT a FireflyException). The renderable asks
    // ErrorPageRenderer::handles() first — the page is for a caller that NAMED text/html — and
    // rendersProblem() second, which claims a JSON client, a json-paths URL and, through
    // `firefly.web.error-page.problem-fallback`, a caller that named nothing acceptable at all.
    $this->getJson('/boom/generic')
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json');

    // A WILDCARD is not an opinion. `Accept: */*` is what a bare curl and a default fetch() send, and
    // `acceptsHtml()` answers true for it — so keying the page off that would hand every unadorned
    // command-line request a page of markup. It gets the document instead.
    $this->call('GET', '/boom/generic', server: ['HTTP_ACCEPT' => '*/*'])
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json');

    // And so does a caller that sent no Accept at all. '' is as close as this harness gets: Symfony's
    // Request::create() — which every call() here goes through — REPLACES an absent HTTP_ACCEPT with a
    // browser's, and the predicate reads `headers->get('Accept', '')`, so an empty header and an absent
    // one are the same string by the time it is asked. ErrorPageTest covers the genuinely absent one.
    $this->call('GET', '/boom/generic', server: ['HTTP_ACCEPT' => ''])
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json');

    // Same route, a caller that NAMED text/html: this one, and only this one, gets the page. Dropping the
    // handles() branch (rendering every generic Throwable as a document) would fail the assertion below.
    $html = $this->get('/boom/generic', ['Accept' => 'text/html']);

    /** @var Response $base */
    $base = $html->baseResponse;
    expect((string) $base->headers->get('Content-Type'))->not->toContain('application/problem+json');
});

/*
 * AND THREE THROWABLES ARE NOT THIS PACKAGE'S TO ANSWER IN EITHER SHAPE. Handler::render() consults
 * renderViaCallbacks() — where the renderable above is registered — BEFORE the `match (true)` that resolves
 * HttpResponseException, AuthenticationException and ValidationException. None of the three is a
 * FireflyException or an HttpExceptionInterface, so ProblemMapper drops each to its default arm and answers
 * 500 / INTERNAL_ERROR / "An unexpected error occurred." — a described failure replaced by an opaque one,
 * which is the shape this wave exists to remove. Only the real pipeline can falsify it: a unit test of the
 * predicate cannot see which exceptions Laravel's own handler would have resolved one arm later.
 */
it('leaves a Laravel ValidationException to Laravel, so a 422 keeps its status and its field errors', function () {
    /** @var WebCapstoneTestCase $this */
    // POST /boom/validated runs Laravel's own validator, not LaraFly's #[Valid] — an ordinary thing for an
    // application built on this framework to do.
    $this->postJson('/boom/validated', ['email' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'The email field must be a valid email address.');

    // The wildcard caller is the one the fallback introduced and the one it broke: claiming it turned
    // Laravel's redirect-back-with-errors into a 500 problem+json with no `errors` member in it.
    $wildcard = $this->call('POST', '/boom/validated', ['email' => 'nope'], server: ['HTTP_ACCEPT' => '*/*']);

    $wildcard->assertStatus(302)->assertSessionHasErrors(['email']);
    expect((string) $wildcard->headers->get('Content-Type'))->not->toContain('application/problem+json');
});

it('leaves a Laravel AuthenticationException to Laravel, so a 401 stays a 401 and a person still reaches the login page', function () {
    /** @var WebCapstoneTestCase $this */
    $this->getJson('/boom/unauthenticated')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');

    // Handler::unauthenticated() sends anyone who did not ask for JSON to the login page. Claiming the
    // exception published a 500 to both, which is a sign-in prompt turned into an internal error.
    $this->call('GET', '/boom/unauthenticated', server: ['HTTP_ACCEPT' => '*/*'])
        ->assertStatus(302)
        ->assertRedirect('/boom/login');
});

it('leaves an HttpResponseException to Laravel, so the response the application already built survives', function () {
    /** @var WebCapstoneTestCase $this */
    // The most literal form of the failure: this exception CARRIES the response to return, and Laravel's
    // handler returns it verbatim one `match` arm after the renderable. Describing it instead threw that
    // response away and answered 500. Thrown from the FILTER CHAIN rather than from a controller, because
    // Illuminate\Routing\Route::run() catches it inside the route and it would never reach the handler at
    // all — see CarriedResponseFilter.
    $response = $this->call('GET', '/'.CarriedResponseFilter::PATH, server: ['HTTP_ACCEPT' => '*/*']);

    $response->assertStatus(418);
    expect((string) $response->getContent())->toBe(CarriedResponseFilter::BODY);
});

it('answers a malformed #[PathVariable(pattern:)] segment with the entity\'s own 404 through the real pipeline', function () {
    /** @var WebCapstoneTestCase $this */
    // RoomsController::show declares PathVariable::UUID with ROOM_NOT_FOUND; the resolver refuses the
    // segment before the controller, and the problem renderer answers it exactly as a missing row would be.
    $this->getJson('/rooms/not-a-uuid')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'ROOM_NOT_FOUND')
        ->assertJsonPath('detail', 'That room does not exist, or is not yours.')
        ->assertHeader('X-Correlation-Id');

    $this->getJson('/rooms/0f8fad5b-d9cb-469f-a165-70867728950e')
        ->assertStatus(200)
        ->assertExactJson(['id' => '0f8fad5b-d9cb-469f-a165-70867728950e']);
});

it('answers a wrong verb as a 405 with the reason phrase, a client sentence, `allowed` and the Allow header', function () {
    /** @var WebCapstoneTestCase $this */
    $response = $this->deleteJson('/balances/7');

    $response->assertStatus(405)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('title', 'Method Not Allowed')
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')
        ->assertJsonPath('detail', 'This address only accepts GET.')
        ->assertJsonPath('allowed', ['GET'])
        ->assertHeader('Allow');

    expect($response->headers->get('Allow'))->toContain('GET');
});

it('withholds an unhandled exception from problem+json even though the test app runs with app.debug on, and names the reference', function () {
    /** @var WebCapstoneTestCase $this */
    // BoomController throws a plain RuntimeException; its message must not reach the wire whatever app.debug says.
    config()->set('app.debug', true);
    $response = $this->getJson('/boom/generic');

    $response->assertStatus(500)
        ->assertJsonPath('code', 'INTERNAL_ERROR')
        ->assertHeader('X-Correlation-Id');

    /** @var string $traceId */
    $traceId = $response->json('traceId');
    /** @var string $detail */
    $detail = $response->json('detail');

    expect($response->headers->get('X-Correlation-Id'))->toBe($traceId)
        ->and($detail)->toContain($traceId)
        ->and($detail)->not->toContain('kaboom');
});

it('withholds the 404 sentence LARAVEL generates, after its own handler has rewritten the exception', function () {
    /** @var WebCapstoneTestCase $this */
    // The whole point of driving this through the real pipeline: Handler::prepareException() turns
    // BoomController::enumCase()'s BackedEnumCaseNotFoundException into a plain NotFoundHttpException
    // carrying "Case [pending] not found on Backed Enum [App\Enums\Status]." BEFORE renderViaCallbacks()
    // reaches LaraFly's renderable — a ModelNotFoundException, the ordinary route-model-binding miss, takes
    // the identical path. A unit test that constructs the mapper's input by hand cannot prove that ordering;
    // this one fails the moment Laravel moves the rewrite or changes the wording.
    $response = $this->getJson('/boom/enum-case');

    $response->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
        ->assertJsonPath('detail', ProblemMapper::NOTHING_HERE);

    // The class name is the disclosure, so the assertion is against the RAW body and not the decoded member:
    // a sentence smuggled into `title` or an extension would satisfy the path assertion above.
    expect((string) $response->getContent())->not->toContain('Enums')
        ->and((string) $response->getContent())->not->toContain('Backed Enum');
});

/*
 * A BYTE THAT IS NOT UTF-8 USED TO COST THE WHOLE DOCUMENT. The renderer encoded with JSON_THROW_ON_ERROR,
 * so one latin-1 byte anywhere in the payload raised a JsonException OUT of the error handler and the
 * caller received a blank 500 from the web server with nothing in it. Both cases below are driven through
 * the real kernel for that reason: the blank 500 is not something the renderer returns, it is what the
 * layer above it does with the exception the renderer threw, and a test that calls render() directly can
 * only ever observe the throw.
 */
it('answers a controller that threw with a non-UTF-8 byte with a problem document, not a blank 500', function () {
    /** @var WebCapstoneTestCase $this */
    $response = $this->getJson('/errors/latin-1');

    $response->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'LEDGER_CONFLICT')
        ->assertJsonPath('category', 'business');

    // Decodable, and still the sentence it was built from: the byte was substituted, not the document lost.
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['detail'])->toContain('The ledger for')
        ->and($payload['detail'])->toContain('disagrees.')
        ->and($payload['traceId'])->toBe($response->headers->get('X-Correlation-Id'));
});

it('answers a member json_encode refuses with the minimal document, still describing the failure it was built for', function () {
    /** @var WebCapstoneTestCase $this */
    // INF in an extension member is what substitution cannot answer, so this is the fallback on the wire.
    $response = $this->getJson('/errors/unencodable');

    $response->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 409)
        ->assertJsonPath('title', 'Conflict')
        ->assertJsonPath('code', 'LEDGER_CONFLICT')
        // Degraded, not contradictory: a 409 whose category read `internal` would have a client branching
        // on the status and a client branching on the category disagreeing about the same document.
        ->assertJsonPath('category', 'business')
        ->assertJsonPath('severity', 'warning')
        ->assertJsonPath('detail', 'The ledger disagrees.')
        // The member stays and says what it holds. It used to be deleted, which published a degraded
        // document that a healthy one could not be told apart from.
        ->assertJsonPath('ratio', 'INF');

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['traceId'])->toBe($response->headers->get('X-Correlation-Id'))
        ->and($payload['correlationId'])->toBe($response->headers->get('X-Correlation-Id'));
});

it('answers an extension member whose own accessor throws with the minimal document, not a blank 500', function () {
    /** @var WebCapstoneTestCase $this */
    // The failure json_encode does not REPORT, it merely propagates: encoding an object calls the
    // application's code, so a jsonSerialize() or an Eloquent accessor that throws comes out of
    // json_encode with no JsonException anywhere in it. `catch (JsonException)` let it escape render(),
    // and the caller got the same blank 500 this whole task exists to remove.
    $response = $this->getJson('/errors/throwing-extension');

    $response->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 409)
        ->assertJsonPath('code', 'LEDGER_CONFLICT')
        ->assertJsonPath('category', 'business')
        ->assertJsonPath('detail', 'The ledger disagrees.')
        // Named rather than deleted, which is get_debug_type()'s answer and ConfigPropsEndpoint's idiom.
        ->assertJsonPath('balance', 'JsonSerializable@anonymous');

    // The thrower's own sentence is an internal detail and must not ride out on the document either.
    expect((string) $response->getContent())->not->toContain('accessor could not read');
});

/*
 * AND THE SWALLOWED THROWABLE REACHES THE LOG, THROUGH THE WIRING AND NOT THROUGH A CONSTRUCTOR ARGUMENT.
 * The renderer takes an optional logger, and a unit test can hand it one; what a unit test cannot prove is
 * that the application's own logger ever arrives — WebServiceProvider builds this singleton itself, and for
 * one release it passed only the settings object, so the reporting arm would have been dead in every real
 * boot while every unit test around it stayed green. The failure below is the one the reviewer reproduced:
 * a RuntimeException raised by an extension member's accessor, recorded in no place at all.
 */
it('reports the degraded problem document through the application logger the provider wired', function () {
    /** @var WebCapstoneTestCase $this */
    $log = new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<string, mixed>}> */
        public array $lines = [];

        /**
         * @param  array<string, mixed>  $context
         */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = ['message' => (string) $message, 'context' => $context];
        }
    };

    // Swapped BEFORE the request, which is when the renderer singleton is first resolved: the provider's
    // closure reads the container's logger at construction time, so this is the application's logger as
    // far as it is concerned.
    Log::swap($log);

    $response = $this->getJson('/errors/throwing-extension');
    $response->assertStatus(409);

    $degraded = array_values(array_filter(
        $log->lines,
        static fn (array $line): bool => str_contains($line['message'], 'degraded'),
    ));

    $cause = $degraded[0]['context']['exception'] ?? null;

    expect($degraded)->toHaveCount(1)
        // The cause, which nothing anywhere recorded before.
        ->and($cause)->toBeInstanceOf(Throwable::class)
        ->and($cause instanceof Throwable ? $cause->getMessage() : '')->toContain('the accessor could not read the balance')
        // And the reference the caller is holding, so the two ends of the failure join up.
        ->and($degraded[0]['context']['reference'])->toBe($response->headers->get('X-Correlation-Id'));
});
