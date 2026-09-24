<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Kernel\Exception\Business\PaymentRequiredException;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Psr\Log\AbstractLogger;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('renders a FireflyException as 404 application/problem+json', function () {
    $response = (new ProblemDetailsRenderer)->render(
        new ResourceNotFoundException('Account 42 not found'),
        Request::create('/accounts/42', 'GET'),
    );

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json');

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe(404)
        ->and($payload['title'])->toBe('Not Found')
        ->and($payload['code'])->toBe('RESOURCE_NOT_FOUND')
        ->and($payload['category'])->toBe('business')
        ->and($payload['detail'])->toBe('Account 42 not found')
        // RFC 9457 §3.1.5: `instance` is a URI reference, and a RELATIVE one resolves against the document's
        // base URI — so `accounts/42` served from /accounts/42 identifies /accounts/accounts/42. One
        // character, and the member stops identifying the occurrence it exists to identify. Spring's
        // ProblemDetail sets it from the request URI for the same reason.
        ->and($payload['instance'])->toBe('/accounts/42');
});

it('converts a generic Throwable to a 500 problem+json', function () {
    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), Request::create('/x'));

    expect($response->getStatusCode())->toBe(500);
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe(500)->and($payload['category'])->toBe('internal');
});

it('preserves the real status of a Symfony HttpExceptionInterface instead of forcing 500 (bug fix)', function () {
    // A URL with NO matching route at all raises Symfony's NotFoundHttpException — distinct from a Firefly
    // ResourceNotFoundException thrown by a MATCHED route's handler (covered above). Before this fix EVERY
    // non-FireflyException — including this one — was collapsed into a 500 INTERNAL_ERROR, so an unrouted URL
    // never actually 404'd for a JSON client (caught by firefly/actuator's HTTP capstone master-gate-off test).
    $response = (new ProblemDetailsRenderer)->render(
        new NotFoundHttpException('The route actuator/health could not be found.'),
        Request::create('/actuator/health'),
    );

    expect($response->getStatusCode())->toBe(404);
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe(404)
        ->and($payload['code'])->toBe('RESOURCE_NOT_FOUND')
        ->and($payload['category'])->toBe('framework')
        // The router's own sentence ("The route actuator/health could not be found.") names a "route", which
        // is the framework's word, and repeats a path the document already carries as `instance`. A person
        // reads the product's sentence instead; an author's abort(404, '…') message still stands (see below).
        ->and($payload['detail'])->toBe(ProblemMapper::NOTHING_HERE);
});

/*
 * THE GATE THE JSON RENDERER USED TO SHARE WITH THE HTML PAGE — `firefly.web.error-page.trace`, which follows
 * `app.debug` — was the wrong gate for a machine surface. Every local and compose environment sets APP_DEBUG,
 * and a console fed by problem+json rendered a duplicate-key insert as the DSN, the tenant id and the full
 * statement in a red banner. The HTML page keeps `trace` (a developer reading a page on their own machine);
 * the problem document has its own switch, `firefly.web.problem.disclose`, and it defaults to OFF whatever
 * app.debug says.
 */
it('withholds an unhandled message from problem+json even when app.debug (trace) is on', function () {
    $renderer = new ProblemDetailsRenderer(new ErrorPageSettings(trace: true));

    $body = (string) $renderer->render(
        new RuntimeException('SQLSTATE[23505] duplicate key (Connection: pgsql, Host: 127.0.0.1, SQL: insert into cp.plan_steps …)'),
        Request::create('/api/x'),
    )->getContent();

    expect($body)->not->toContain('SQLSTATE')
        ->not->toContain('127.0.0.1')
        ->toContain('An unexpected error occurred.');
});

it('reads the problem gate from firefly.web.problem.disclose and never from app.debug', function () {
    $debugOnly = ErrorPageSettings::fromConfig(new Config(new Repository(['app' => ['debug' => true]])));
    $explicit = ErrorPageSettings::fromConfig(new Config(new Repository([
        'app' => ['debug' => false],
        'firefly' => ['web' => ['problem' => ['disclose' => true]]],
    ])));

    expect($debugOnly->trace)->toBeTrue()
        ->and($debugOnly->disclose)->toBeFalse()
        ->and($explicit->disclose)->toBeTrue();
});

/*
 * EVERY PROBLEM CARRIES THE REFERENCE A PERSON CAN QUOTE. CorrelationIdFilter mints or reads X-Correlation-Id
 * at order -100 and publishes it through Context; the renderer used to pass no traceId at all, so the body
 * a person screenshotted and the log line an operator searched for had nothing in common. The id is now in
 * the body as `traceId` and on the response as X-Correlation-Id, and an opaque 5xx sentence names it.
 */
it('puts the request\'s correlation id in the body as traceId and on the response header', function () {
    $request = Request::create('/api/x');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-123');

    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), $request);
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['traceId'])->toBe('corr-123')
        ->and($response->headers->get(CorrelationIdFilter::HEADER))->toBe('corr-123')
        // The opaque sentence names the reference, so the screenshot and the log line share it.
        ->and($payload['detail'])->toBe('An unexpected error occurred. It has been logged; quote reference corr-123 if you report it.');
});

it('mints a correlation id when the request carried none, so a problem is never unreferenced', function () {
    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), Request::create('/api/x'));
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['traceId'])->toBeString()->not->toBe('')
        ->and($response->headers->get(CorrelationIdFilter::HEADER))->toBe($payload['traceId']);
});

it('keeps a FireflyException\'s own sentence and still adds the reference', function () {
    $request = Request::create('/api/x');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-9');

    $response = (new ProblemDetailsRenderer)->render(new ServiceUnavailableException('The signing keys are unreachable.', 'JWKS_UNAVAILABLE'), $request);
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['detail'])->toBe('The signing keys are unreachable.')
        ->and($payload['traceId'])->toBe('corr-9')
        ->and($response->getStatusCode())->toBe(503)
        // A 503 says when to come back: the two things that produce one — a starved pool, an unreachable
        // upstream — clear in seconds when they clear at all.
        ->and($response->headers->get('Retry-After'))->toBe((string) ProblemDetailsRenderer::RETRY_AFTER_SECONDS);
});

it('answers PHP\'s execution-time limit as a 503 the caller can retry, not a 500 with the engine\'s sentence', function () {
    // Symfony's handler turns the engine's E_ERROR into a FatalError (an \Error) whose only signal is the
    // message prefix, stable since PHP 4. The request was not wrong; the server stopped it.
    $fatal = new FatalError('Maximum execution time of 30 seconds exceeded', 0, ['type' => E_ERROR, 'file' => '/srv/x.php', 'line' => 1, 'message' => 'Maximum execution time of 30 seconds exceeded']);

    $response = (new ProblemDetailsRenderer)->render($fatal, Request::create('/api/x'));
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(503)
        ->and($payload['code'])->toBe(ProblemMapper::EXECUTION_TIME_EXCEEDED)
        ->and($payload['category'])->toBe('infrastructure')
        ->and($payload['detail'])->toContain('30 seconds')
        ->not->toContain('/srv/x.php')
        ->and($response->headers->get('Retry-After'))->toBe((string) ProblemDetailsRenderer::RETRY_AFTER_SECONDS);
});

/*
 * A WRONG VERB, IN THE PRODUCT'S WORDS. The router raises Symfony's MethodNotAllowedHttpException and the
 * document used to be `{"title": "Error", "detail": "The GET method is not supported for route api/v1/…/cancel.
 * Supported methods: POST."}` — no 405 title, the framework's sentence with "route" and a path in it, and the
 * `Allow` header the exception carried dropped on the floor. Every part of that is fixed here: the title is
 * the reason phrase, the sentence is written for a person, the methods are in an `allowed` extension member
 * and the Allow header is copied through.
 */
it('renders a 405 with its title, a client sentence, an allowed extension and the Allow header', function () {
    $response = (new ProblemDetailsRenderer)->render(
        new MethodNotAllowedHttpException(['POST', 'HEAD'], 'The GET method is not supported for route api/v1/queue/turns/1/cancel. Supported methods: POST, HEAD.'),
        Request::create('/api/v1/queue/turns/1/cancel', 'GET'),
    );
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(405)
        ->and($payload['title'])->toBe('Method Not Allowed')
        ->and($payload['code'])->toBe('METHOD_NOT_ALLOWED')
        ->and($payload['detail'])->toBe('This address only accepts POST.')
        ->and($payload['detail'])->not->toContain('route')
        ->and($payload['allowed'])->toBe(['POST'])
        ->and($response->headers->get('Allow'))->toBe('POST, HEAD');
});

it('lists several allowed methods as a person would say them', function () {
    $response = (new ProblemDetailsRenderer)->render(
        new MethodNotAllowedHttpException(['GET', 'POST', 'PUT']),
        Request::create('/api/x', 'DELETE'),
    );
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['detail'])->toBe('This address only accepts GET, POST or PUT.')
        ->and($payload['allowed'])->toBe(['GET', 'POST', 'PUT']);
});

it('leaves an author\'s abort(404, …) sentence alone while replacing the router\'s', function () {
    $author = ProblemMapper::toFireflyException(new NotFoundHttpException('No such tenant.'));
    $router = ProblemMapper::toFireflyException(new NotFoundHttpException('The route api/nope could not be found.'));

    expect($author->getMessage())->toBe('No such tenant.')
        ->and($router->getMessage())->toBe(ProblemMapper::NOTHING_HERE);
});

it('keeps a model class and a primary key out of `detail` when Laravel rewrote the 404 itself', function () {
    // The router is not the only source of a generated 404. Handler::prepareException() turns a
    // ModelNotFoundException — the ordinary route-model-binding miss, the most common 404 an application
    // has — into `new NotFoundHttpException($e->getMessage(), $e)` before renderViaCallbacks() is reached,
    // so this document was publishing an application FQCN and a row id to whoever followed a stale link.
    $response = (new ProblemDetailsRenderer)->render(
        new NotFoundHttpException('No query results for model [App\Models\Order] 42'),
        Request::create('/orders/42'),
    );

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(404)
        ->and($payload['detail'])->toBe(ProblemMapper::NOTHING_HERE)
        // Asserted against the RAW body too: a sentence smuggled into another member is the same leak.
        ->and((string) $response->getContent())->not->toContain('Models')
        ->and(ProblemMapper::toFireflyException(
            new NotFoundHttpException((new BackedEnumCaseNotFoundException('App\Enums\Status', 'pending'))->getMessage()),
        )->getMessage())->toBe(ProblemMapper::NOTHING_HERE);
});

it('spreads a FireflyException\'s extension members and title into the document', function () {
    $response = (new ProblemDetailsRenderer)->render(
        (new PaymentRequiredException('The Team edition includes up to five workers.', 'EDITION_LIMIT'))
            ->withExtensions(['field' => 'workers', 'limit' => 5])
            ->withTitle('Your plan does not include this'),
        Request::create('/api/workers', 'POST'),
    );
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(402)
        ->and($payload['title'])->toBe('Your plan does not include this')
        ->and($payload['code'])->toBe('EDITION_LIMIT')
        ->and($payload['field'])->toBe('workers')
        ->and($payload['limit'])->toBe(5);
});

it('publishes the W3C trace id as traceId and the correlation id as its own member', function () {
    $request = Request::create('/orders');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-42');
    $request->attributes->set(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');

    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), $request);
    /** @var array<string,mixed> $body */
    $body = json_decode((string) $response->getContent(), true);

    expect($body['traceId'])->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($body['correlationId'])->toBe('corr-42')
        ->and($response->headers->get(CorrelationIdFilter::HEADER))->toBe('corr-42')
        ->and($response->headers->get('X-Trace-Id'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('falls back to the correlation id, and writes no trace header, when there is no span', function () {
    $request = Request::create('/orders');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-42');

    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), $request);
    /** @var array<string,mixed> $body */
    $body = json_decode((string) $response->getContent(), true);

    expect($body['traceId'])->toBe('corr-42')
        ->and($body['correlationId'])->toBe('corr-42')
        ->and($response->headers->has('X-Trace-Id'))->toBeFalse();
});

/*
 * THE ERROR HANDLER MUST NOT FAIL WHILE HANDLING THE ERROR. json_encode with JSON_THROW_ON_ERROR raises out
 * of render() on any byte that is not valid UTF-8, and those bytes arrive on this path as a matter of
 * routine: a driver message quoting a latin-1 column, a request header echoed into an extension member, a
 * file name off a non-UTF-8 filesystem. What the caller got was not a worse document — it was NO document,
 * a blank 500 from the web server, with the real failure buried under a JsonException.
 */
it('substitutes an invalid byte rather than throwing out of the renderer', function () {
    $broken = (new ConflictException("caf\xE9 is closed", 'CAFE_CLOSED'))->withExtensions(['who' => "ada\xB1\x31"]);

    $response = (new ProblemDetailsRenderer(new ErrorPageSettings(disclose: true)))->render($broken, Request::create('/api/x'));

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($payload['code'])->toBe('CAFE_CLOSED')
        // Substituted, not dropped: the document still describes the failure it was built for.
        ->and($payload['detail'])->toContain('is closed')
        ->and($payload['who'])->toContain('ada');
});

it('falls back to a minimal document rather than raising when a member cannot be encoded at all', function () {
    // INF is the case JSON_INVALID_UTF8_SUBSTITUTE does not cover: a float an application put in an
    // extension member at the throw site. The document that comes back is smaller and still true.
    $impossible = (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))->withExtensions(['ratio' => INF]);

    $request = Request::create('/api/x');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-77');

    $response = (new ProblemDetailsRenderer)->render($impossible, $request);

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(409)
        ->and($payload['status'])->toBe(409)
        ->and($payload['title'])->toBe('Conflict')
        ->and($payload['code'])->toBe('LEDGER_CONFLICT')
        // SMALLER, NOT CONTRADICTORY. A document whose status and code say business conflict while its
        // category says `internal` is one no client can branch on twice and get the same answer, and the
        // real values are plain strings ErrorResponse wrote — they can never be why the encode failed.
        ->and($payload['category'])->toBe('business')
        ->and($payload['severity'])->toBe('warning')
        // The authored sub-500 sentence survives too: ProblemMapper's disclosure gate had already cleared
        // it, so replacing it with the opaque one would withhold nothing and lose everything.
        ->and($payload['detail'])->toBe('The ledger disagrees.')
        // And the degraded body is still correlatable, which is the one action it exists to make possible.
        ->and($payload['traceId'])->toBe('corr-77')
        ->and($payload['correlationId'])->toBe('corr-77')
        // Root-relative here too, for RFC 9457 §3.1.5's reason — see the first test in this file. The
        // degraded document carries the SAME member the healthy one would have carried, which is the whole
        // promise minimal() makes: it diffs against the full document member for member.
        ->and($payload['instance'])->toBe('/api/x')
        ->and($payload['timestamp'])->toBeString()
        // THE MEMBER STAYS AND SAYS WHAT IT IS. Deleting it published a document a healthy one could not
        // be told apart from, and took every OTHER extension member down with it — including `allowed`,
        // which ProblemMapper itself publishes on a 405. `INF` rather than `float`, because `float` is the
        // one true thing about this value that helps nobody.
        ->and($payload['ratio'])->toBe('INF')
        // And it is LAST, after `timestamp`, exactly where ErrorResponse::toArray() puts the open
        // namespace in a healthy document.
        ->and(array_key_last($payload))->toBe('ratio');
});

it('keeps every extension member that was never in question, and names only the one that was', function () {
    // The real shape of the failure: an application hangs three pieces of context off a throw site and
    // exactly ONE of them is unencodable. Dropping the namespace wholesale lost the tenant and the order
    // — the two members that make the failure legible — to pay for the one nobody could read.
    $impossible = (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))->withExtensions([
        'tenant' => 'acme',
        'attempts' => 3,
        'allowed' => ['GET', 'POST'],
        'balance' => fopen('php://memory', 'r'),
    ]);

    $response = (new ProblemDetailsRenderer)->render($impossible, Request::create('/api/x'));

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['tenant'])->toBe('acme')
        ->and($payload['attempts'])->toBe(3)
        ->and($payload['allowed'])->toBe(['GET', 'POST'])
        // get_debug_type()'s spelling, which is ConfigPropsEndpoint::value()'s answer to the same question:
        // naming the type never calls the value's own code, and calling the value's own code is what threw.
        ->and($payload['balance'])->toBe('resource (stream)');
});

it('reports the throwable it swallowed, with the document\'s own reference, when an extension member cannot be encoded', function () {
    // THE ARM THAT USED TO FAIL UNOBSERVABLY. `catch (Throwable) { $json = false; }` put an arbitrary
    // application exception into a pair of braces and published a degraded document with no marker, no
    // header and no log line — so an operator whose application was losing its extension members from
    // every problem document it published had no way to learn that it was happening, or why.
    $log = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
        public array $lines = [];

        /**
         * @param  array<string, mixed>  $context
         */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };

    $thrower = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('the accessor could not read the balance either');
        }
    };

    $request = Request::create('/api/x');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-99');

    $renderer = new ProblemDetailsRenderer(new ErrorPageSettings, $log);
    $response = $renderer->render(
        (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))->withExtensions(['balance' => $thrower]),
        $request,
    );

    $cause = $log->lines[0]['context']['exception'] ?? null;

    expect($log->lines)->toHaveCount(1)
        ->and($log->lines[0]['level'])->toBe('error')
        ->and($log->lines[0]['message'])->toContain('degraded')
        // The cause itself, under the key PSR-3 reserves for it — so the class, the sentence and the trace
        // all reach the log, and none of them reaches the caller.
        ->and($cause)->toBeInstanceOf(Throwable::class)
        ->and($cause instanceof Throwable ? $cause->getMessage() : '')->toContain('the accessor could not read the balance')
        // And the document's own reference, which is the only thing that joins this line to the body a
        // person is holding. A log line an operator cannot reach from the response is half a record.
        ->and($log->lines[0]['context']['reference'])->toBe('corr-99')
        ->and(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR))
        ->toHaveKey('traceId', 'corr-99');
});

it('says nothing, and still answers, when the document encodes cleanly', function () {
    $log = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $lines = [];

        /**
         * @param  array<string, mixed>  $context
         */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = (string) $message;
        }
    };

    $response = (new ProblemDetailsRenderer(new ErrorPageSettings, $log))
        ->render(new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'), Request::create('/api/x'));

    // The happy path is the overwhelmingly common one: a line per rendered problem would drown the line
    // that means something.
    expect($log->lines)->toBe([])
        ->and($response->getStatusCode())->toBe(409);
});

it('still answers with a document when the logger itself throws', function () {
    // The fix must not reintroduce the bug one layer up. The logger is a live collaborator on a failing
    // box — a full disk, a channel whose endpoint is the thing that went down — and a throw from the
    // reporting call would escape render() and produce exactly the blank 500 the encoder was made total
    // to prevent.
    $log = new class extends AbstractLogger
    {
        /**
         * @param  array<string, mixed>  $context
         */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('the log channel is down too');
        }
    };

    $response = (new ProblemDetailsRenderer(new ErrorPageSettings, $log))->render(
        (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))->withExtensions(['ratio' => INF]),
        Request::create('/api/x'),
    );

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(409)
        ->and($payload['code'])->toBe('LEDGER_CONFLICT')
        ->and($payload['ratio'])->toBe('INF');
});

it('falls back to a minimal document when an extension member\'s own jsonSerialize() throws', function () {
    // THE CASE JSON_THROW_ON_ERROR DOES NOT REACH. json_encode does not merely refuse an object — it CALLS
    // the object's code, and whatever that code raises comes out of json_encode with no error state set
    // and no JsonException in it, so `catch (JsonException)` let it straight through render(). Extension
    // members are `mixed` and chosen at the throw site, so an Eloquent model under preventLazyLoading or
    // any accessor that reads the database is a realistic member — and it is likeliest to throw exactly
    // when the database is the thing that already failed, i.e. while this renderer describes that failure.
    $thrower = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('the accessor could not read the balance either');
        }
    };

    $impossible = (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))
        ->withExtensions(['balance' => $thrower]);

    $request = Request::create('/api/x');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-88');

    $response = (new ProblemDetailsRenderer)->render($impossible, $request);

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(409)
        ->and($payload['status'])->toBe(409)
        ->and($payload['code'])->toBe('LEDGER_CONFLICT')
        ->and($payload['category'])->toBe('business')
        ->and($payload['detail'])->toBe('The ledger disagrees.')
        // Still correlatable, which is the one action the degraded document exists to keep possible.
        ->and($payload['traceId'])->toBe('corr-88')
        // The member is NAMED, not deleted — get_debug_type()'s answer for an anonymous class is the
        // interface it implements. What the thrower SAID is an internal detail and stays out.
        ->and($payload['balance'])->toBe('JsonSerializable@anonymous')
        ->and((string) $response->getContent())->not->toContain('accessor could not read');
});

it('keeps the field errors, and drops only the rejected value, when a rejected value cannot be encoded', function () {
    // FieldError::$rejectedValue is `mixed` — literally whatever the client sent, since the validators fill
    // it with Arr::get($data, $field) off the decoded body — so a 422 is a real route into the fallback,
    // and json_decode('{"ratio": 1e999}') is float(INF), i.e. any caller can reach it by posting a number.
    // Two things must survive that. The category, because a 422 saying `category: internal` sends a
    // generated client branching on `category == 'validation'` down the wrong arm. And the errors
    // THEMSELVES, because sending it down the RIGHT arm with nothing to render is the same bug wearing a
    // different hat — and the second field below, whose rejected value is an ordinary string, was never
    // implicated in the failure at all.
    $invalid = new ValidationException('Validation failed', [
        new FieldError('ratio', 'must be a number', rejectedValue: INF),
        new FieldError('email', 'must be a valid email address', code: 'EMAIL_INVALID', rejectedValue: 'nope', constraint: 'Email'),
    ]);

    $response = (new ProblemDetailsRenderer)->render($invalid, Request::create('/api/x'));

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(422)
        ->and($payload['status'])->toBe(422)
        ->and($payload['category'])->toBe('validation')
        ->and($payload['code'])->toBe('VALIDATION_ERROR')
        ->and($payload['detail'])->toBe('Validation failed')
        // The member stays, and every declared-string part of both entries with it.
        ->and($payload['errors'])->toBe([
            ['field' => 'ratio', 'message' => 'must be a number'],
            ['field' => 'email', 'message' => 'must be a valid email address', 'code' => 'EMAIL_INVALID', 'constraint' => 'Email'],
        ])
        // `rejectedValue` is the ONLY `mixed` member of a FieldError, so it is the only one that goes —
        // from both entries, because the renderer cannot know which of them json_encode refused.
        ->and((string) $response->getContent())->not->toContain('rejectedValue')
        ->and((string) $response->getContent())->not->toContain('nope')
        // `errors` stays LAST, where STANDARD_MEMBERS has it, so the degraded document still reads like
        // the full one to a person diffing the two.
        ->and(array_key_last($payload))->toBe('errors');
});

it('answers with a document even when the status itself is the only thing left', function () {
    // The last branch, exercised directly: whatever the payload holds, the renderer returns valid JSON.
    $response = (new ProblemDetailsRenderer)->render(
        (new ConflictException('x', 'X'))->withExtensions(['a' => NAN, 'b' => "\xC3\x28"]),
        Request::create('/api/x'),
    );

    expect(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR))->toBeArray()
        ->and($response->getStatusCode())->toBe(409);
});

it('answers an extension member that refers to itself, instead of recursing until the stack goes', function () {
    // The one way keeping the open namespace could have swapped a failed ENCODE for a failed STACK: an
    // array that holds itself through a reference is what json_encode reports as "Recursion detected", and
    // a walk of it with no bound would never come back at all. The depth cap is what makes the walk total.
    $cycle = ['tenant' => 'acme'];
    $cycle['self'] = &$cycle;

    $response = (new ProblemDetailsRenderer)->render(
        (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))->withExtensions(['context' => $cycle]),
        Request::create('/api/x'),
    );

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $context = $payload['context'] ?? null;

    // The document comes back, the member is there, and the readable part of it survived the trip down.
    expect($response->getStatusCode())->toBe(409)
        ->and($payload['code'])->toBe('LEDGER_CONFLICT')
        ->and($context)->toBeArray()
        ->and(is_array($context) ? $context['tenant'] : null)->toBe('acme');
});

it('identifies the occurrence with a root-relative reference, at every depth and at the site root', function () {
    $renderer = new ProblemDetailsRenderer;

    $deep = json_decode((string) $renderer->render(new ResourceNotFoundException('x'), Request::create('/api/v1/orders/42/lines/7'))->getContent(), true);
    $root = json_decode((string) $renderer->render(new ResourceNotFoundException('x'), Request::create('/'))->getContent(), true);

    /** @var array<string,mixed> $deep */
    /** @var array<string,mixed> $root */
    expect($deep['instance'])->toBe('/api/v1/orders/42/lines/7')
        ->and($root['instance'])->toBe('/');
});

it('carries about:blank as its problem type by default, the way Spring\'s ProblemDetail does', function () {
    $payload = json_decode((string) (new ProblemDetailsRenderer)->render(new ResourceNotFoundException('x'), Request::create('/api/x'))->getContent(), true);

    /** @var array<string,mixed> $payload */
    expect($payload['type'])->toBe('about:blank')
        // The member leads the document with the other standard ones, whatever extensions arrived.
        ->and(array_slice(array_keys($payload), 0, 5))->toBe(['status', 'title', 'code', 'category', 'severity']);
});

it('derives a dereferenceable type from the stable code when the deployment names a base', function () {
    $renderer = new ProblemDetailsRenderer(ErrorPageSettings::fromConfig(new Config(new Repository([
        'firefly' => ['web' => ['problem' => ['type-uri' => 'https://api.example.test/problems']]],
    ]))));

    $payload = json_decode((string) $renderer->render(new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND'), Request::create('/api/orders/42'))->getContent(), true);

    /** @var array<string,mixed> $payload */
    expect($payload['type'])->toBe('https://api.example.test/problems/order-not-found')
        ->and($payload['code'])->toBe('ORDER_NOT_FOUND')
        ->and($payload['instance'])->toBe('/api/orders/42');
});

it('emits no type member at all when a deployment wants the pre-9457 document byte for byte', function () {
    $renderer = new ProblemDetailsRenderer(ErrorPageSettings::fromConfig(new Config(new Repository([
        'firefly' => ['web' => ['problem' => ['type-uri' => '']]],
    ]))));

    $payload = json_decode((string) $renderer->render(new ResourceNotFoundException('x'), Request::create('/api/x'))->getContent(), true);

    /** @var array<string,mixed> $payload */
    expect($payload)->not->toHaveKey('type');
});
