<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Error\ErrorFrame;
use Firefly\Web\Error\ErrorPage;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ErrorReport;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The HTML error page: who gets one, and what it is allowed to say.
 *
 * The second question is the one with teeth. This page exists to show a developer an exception message, a
 * file name and a stack trace, and every one of those is something a production deployment must never send
 * to a stranger. The rule is enforced in ErrorReport rather than in the template — when `trace` is off the
 * report never reads a source file, never walks the trace and never copies the message — so these tests
 * assert against the REPORT as well as the rendered HTML: a template mistake cannot leak what was never
 * gathered, and that is the property worth pinning.
 */
$request = static fn (string $accept = 'text/html', array $server = []): Request => Request::create(
    '/orders/42',
    'GET',
    server: ['HTTP_ACCEPT' => $accept, ...$server],
);

$report = static fn (Throwable $e, ErrorPageSettings $settings, ?Request $request = null): ErrorReport => ErrorReport::of(
    $e,
    $request ?? Request::create('/orders/42'),
    $settings,
    dirname(__DIR__, 4),
    404,
    'Not Found',
    '2026-01-01T00:00:00+00:00',
);

it('answers a browser with a page and everything else with a problem document', function () use ($request) {
    $renderer = new ErrorPageRenderer(new ErrorPageSettings(enabled: true));

    expect($renderer->handles($request('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')))->toBeTrue()
        ->and($renderer->handles($request('application/xhtml+xml')))->toBeTrue()
        // A bare curl sends a wildcard. `acceptsHtml()` says yes to it, which is exactly why the rule is
        // "NAMED text/html" instead — otherwise every unadorned command-line request against an API would
        // start returning HTML, a worse regression than the bug this page fixes.
        ->and($renderer->handles($request('*/*')))->toBeFalse()
        ->and($renderer->handles($request('application/json')))->toBeFalse()
        // JavaScript is going to read a body, not look at a page, even when the browser's Accept says HTML.
        ->and($renderer->handles($request('text/html', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])))->toBeFalse();
});

it('is switched off by configuration, and then never claims a request', function () use ($request) {
    $renderer = new ErrorPageRenderer(new ErrorPageSettings(enabled: false));

    expect($renderer->handles($request('text/html')))->toBeFalse();
});

it('still knows a browser from an API client when the page is switched off', function () use ($request) {
    // `handles()` answers "render this page?"; `prefersHtml()` answers "is a person at a browser asking?".
    // The second is what the security entry point needs to decide on a login redirect, and that decision
    // must not change with a flag whose documented meaning is "use Laravel's stock error page instead".
    $renderer = new ErrorPageRenderer(new ErrorPageSettings(enabled: false, jsonPaths: ['api/*']));
    $browserAccept = ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml'];

    expect($renderer->prefersHtml($request('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')))->toBeTrue()
        ->and($renderer->prefersHtml($request('*/*')))->toBeFalse()
        ->and($renderer->prefersHtml($request('application/json')))->toBeFalse()
        ->and($renderer->prefersHtml($request('text/html', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])))->toBeFalse()
        // The clause that is easiest to omit when this rule is written down in prose, and the one that makes
        // the difference between a 302 to /login and a 401. `wantsJson()` tests the FIRST acceptable type, so
        // a header that PUTS json first is a machine asking even though it also names text/html and carries
        // no X-Requested-With. Stating the rule as "names text/html and is not an XHR" — as three copies of
        // the documentation once did — gets this request exactly backwards.
        ->and($renderer->prefersHtml($request('application/json, text/html')))->toBeFalse()
        ->and($renderer->prefersHtml($request('text/html, application/json')))->toBeTrue()
        // json-paths says what the URL IS, not what the page does, so it still applies with the page off.
        ->and($renderer->prefersHtml(Request::create('/api/orders/9', 'GET', server: $browserAccept)))->toBeFalse()
        ->and($renderer->prefersHtml(Request::create('/orders/9', 'GET', server: $browserAccept)))->toBeTrue()
        // And the page itself still declines everything: the flag gates rendering, not recognition.
        ->and($renderer->handles(Request::create('/orders/9', 'GET', server: $browserAccept)))->toBeFalse();
});

it('gathers nothing to leak when the trace is off', function () use ($report) {
    $error = $report(new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND'), new ErrorPageSettings(trace: false));

    expect($error->detailed)->toBeFalse()
        ->and($error->message)->toBe('')
        ->and($error->exceptionClass)->toBe('')
        ->and($error->location)->toBe('')
        ->and($error->frames)->toBe([])
        ->and($error->previous)->toBe([])
        // The stable code IS published, on purpose: it is what a user quotes into a support ticket and what
        // an operator greps the log for, and it names nothing internal.
        ->and($error->code)->toBe('ORDER_NOT_FOUND');
});

it('keeps the exception out of the rendered production page', function () use ($report) {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $html = ErrorPage::render($report(new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND'), $settings), $settings);

    expect($html)->not->toContain('Order 42 does not exist.')
        ->not->toContain('ResourceNotFoundException')
        ->not->toContain('Stack trace')
        // Nor the page's own advice about how to turn the trace on, which names the framework and a config
        // key to an anonymous visitor.
        ->not->toContain('APP_DEBUG')
        ->toContain('ORDER_NOT_FOUND')
        ->toContain('404');
});

it('shows the throw site, its source and the caller chain when the trace is on', function () use ($report) {
    $settings = new ErrorPageSettings(trace: true, hints: true);
    $error = $report(new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND'), $settings);

    expect($error->detailed)->toBeTrue()
        ->and($error->message)->toBe('Order 42 does not exist.')
        ->and($error->frames)->not->toBeEmpty()
        // PHP's getTrace() starts at the CALLER of the throwing frame, so the throwing line appears nowhere
        // in it and has to be prepended — otherwise the one frame a reader wants first is the one missing.
        ->and($error->frames[0]->call)->toBe('throw')
        ->and($error->frames[0]->line)->toBeGreaterThan(0)
        ->and($error->frames[0]->vendor)->toBeFalse()
        ->and($error->frames[0]->excerpt)->not->toBeEmpty()
        ->and($error->frames[0]->excerpt)->toHaveKey((int) $error->frames[0]->line);

    $html = ErrorPage::render($error, $settings);

    expect($html)->toContain('Order 42 does not exist.')
        ->toContain('Stack trace')
        ->toContain('ErrorPageTest.php');
});

it('reads no source for a vendor frame', function () use ($report) {
    $error = $report(new ResourceNotFoundException('boom', 'X'), new ErrorPageSettings(trace: true));

    // A trace is forty frames of which a handful are the application's. Opening forty files to render code
    // nobody will read is work this page cannot afford — it runs when things are already going wrong.
    foreach ($error->frames as $frame) {
        if ($frame->vendor) {
            expect($frame->excerpt)->toBe([]);
        }
    }

    expect(array_filter($error->frames, static fn ($f): bool => $f->vendor))->not->toBeEmpty();
});

it('follows the previous chain, where the real cause usually is', function () use ($report) {
    $cause = new RuntimeException('the connection was refused');
    $error = $report(new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND', previous: $cause), new ErrorPageSettings(trace: true));

    expect($error->previous)->toHaveCount(1)
        ->and($error->previous[0]['class'])->toBe(RuntimeException::class)
        ->and($error->previous[0]['message'])->toBe('the connection was refused');
});

it('keeps the real status of a routing miss rather than calling it a 500', function () {
    // A URL matching no route at all throws Symfony's NotFoundHttpException, not a FireflyException. Before
    // ProblemMapper was shared, only the JSON renderer knew that; a page built from a second copy of the
    // rule would eventually disagree, and the browser and the client would be told different things about
    // one failure.
    $settings = new ErrorPageSettings(trace: false);
    $renderer = new ErrorPageRenderer($settings);
    $response = $renderer->render(new NotFoundHttpException, Request::create('/nope', 'GET', server: ['HTTP_ACCEPT' => 'text/html']));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8')
        ->and((string) $response->getContent())->toContain('RESOURCE_NOT_FOUND');
});

it('escapes an exception message rather than rendering it as markup', function () use ($report) {
    $settings = new ErrorPageSettings(trace: true);
    $html = ErrorPage::render($report(new ResourceNotFoundException('<script>alert(1)</script>', 'X'), $settings), $settings);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});

it('answers an API path with a problem document even when a browser asks', function () {
    // The header says who is asking; the path says what the URL IS, and the path wins. Without this a
    // developer opening an API URL in a browser is shown a styled page instead of the payload their client
    // will receive — and so is anything that follows a link into the API with a copied browser header.
    $renderer = new ErrorPageRenderer(new ErrorPageSettings(enabled: true, jsonPaths: ['api/*', 'webhooks/*']));

    $browserAccept = ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml'];

    expect($renderer->handles(Request::create('/api/orders/9', 'GET', server: $browserAccept)))->toBeFalse()
        ->and($renderer->handles(Request::create('/webhooks/stripe', 'POST', server: $browserAccept)))->toBeFalse()
        // Everything outside those prefixes still negotiates normally.
        ->and($renderer->handles(Request::create('/orders/9', 'GET', server: $browserAccept)))->toBeTrue()
        ->and($renderer->handles(Request::create('/apiary', 'GET', server: $browserAccept)))->toBeTrue();
});

it('answers an unrouted API path as JSON even when nothing about the request asked for it', function () {
    // Declining the HTML page is only half of what json-paths has to do. A URL under `api/*` matching no
    // route throws a Symfony HttpException — not a FireflyException — and a browser's Accept header makes
    // `expectsJson()` false, so both problem+json branches missed it and the request fell through to
    // Laravel's own error page. An API path answering with a framework's stock HTML is exactly what this
    // setting exists to prevent.
    $renderer = new ErrorPageRenderer(new ErrorPageSettings(enabled: true, jsonPaths: ['api/*']));

    $browser = Request::create('/api/nope', 'GET', server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']);

    expect($renderer->handles($browser))->toBeFalse()
        ->and($renderer->forcesJson($browser))->toBeTrue()
        // Outside the API space nothing is forced, and a caller that expressed no preference still falls
        // through to Laravel rather than having a shape invented for it.
        ->and($renderer->forcesJson(Request::create('/orders/9', 'GET')))->toBeFalse();
});

it('forces nothing at all when the page is switched off', function () {
    $off = new ErrorPageRenderer(new ErrorPageSettings(enabled: false, jsonPaths: ['api/*']));

    expect($off->forcesJson(Request::create('/api/nope', 'GET')))->toBeFalse();
});

it('withholds an unhandled exception message from problem+json in production', function () {
    // The HTML page has always been gated by `trace`; this path had none, so the SAME failure withheld
    // everything from a browser and published a QueryException's SQL and bindings to a client. A generic
    // Throwable's message is an accident — a table name, a bound value, an absolute path on the server —
    // and is never written for the caller.
    $leak = new RuntimeException("SQLSTATE[42S02]: no such table (SQL: select * from users where email = 'ada@example.test')");

    $withheld = ProblemMapper::toFireflyException($leak, disclose: false);
    $shown = ProblemMapper::toFireflyException($leak, disclose: true);

    expect($withheld->getMessage())->toBe(ProblemMapper::OPAQUE)
        ->not->toContain('SQLSTATE')
        ->not->toContain('ada@example.test')
        // The real message is still on the exception, where a log can have it: it is withheld from the
        // response, not thrown away.
        ->and($withheld->getPrevious()?->getMessage())->toBe($leak->getMessage())
        ->and($shown->getMessage())->toContain('SQLSTATE');
});

it('keeps publishing a FireflyException\'s own message, which was written for the caller', function () {
    // The taxonomy exists so an application can say "Order 42 does not exist." to a client. Gating that
    // would turn every deliberate business error into "An unexpected error occurred." — the opposite of the
    // point.
    $business = new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND');

    expect(ProblemMapper::toFireflyException($business, disclose: false)->getMessage())
        ->toBe('Order 42 does not exist.');

    // An abort(404, '…') message is equally author-supplied, so it survives too.
    expect(ProblemMapper::toFireflyException(new NotFoundHttpException('No such tenant.'), disclose: false)->getMessage())
        ->toBe('No such tenant.');
});

it('renders problem+json with the message withheld when the settings say so', function () {
    $renderer = new ProblemDetailsRenderer(new ErrorPageSettings(disclose: false));
    $body = (string) $renderer->render(new RuntimeException('internal detail: /srv/app/.env'), Request::create('/api/x'))->getContent();

    expect($body)->not->toContain('/srv/app/.env')
        ->toContain('An unexpected error occurred.')
        ->toContain('INTERNAL_ERROR');

    // And with the problem gate open — set explicitly, never inherited from app.debug — the real message
    // comes through.
    $debug = new ProblemDetailsRenderer(new ErrorPageSettings(disclose: true));
    expect((string) $debug->render(new RuntimeException('internal detail: /srv/app/.env'), Request::create('/api/x'))->getContent())
        ->toContain('/srv/app/.env');
});

it('defaults to withholding when no settings object was bound at all', function () {
    // A JSON-only deployment may never construct ErrorPageSettings. The default has to be the safe one:
    // an absent gate must not mean an open one.
    expect((string) (new ProblemDetailsRenderer)->render(new RuntimeException('leak me'), Request::create('/api/x'))->getContent())
        ->not->toContain('leak me')
        ->toContain('An unexpected error occurred.');
});

it('publishes the request reference on the production page so a person can quote it', function () {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/orders/42', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'ref-1234-abcd']);
    $error = ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($error->reference)->toBe('ref-1234-abcd')
        ->and($html)->toContain('ref-1234-abcd')
        ->toContain('quote reference ref-1234-abcd if you report it')
        // The reference is the ONLY thing the production 500 adds; the cause stays withheld.
        ->not->toContain('boom')
        ->not->toContain('RuntimeException');
});

it('carries the same reference the problem document does, on the detailed page too', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false);
    $request = Request::create('/orders/42', 'GET', server: ['HTTP_ACCEPT' => 'text/html', 'HTTP_X_CORRELATION_ID' => 'ref-5678-efgh']);
    $renderer = new ErrorPageRenderer($settings, dirname(__DIR__, 4));

    $html = (string) $renderer->render(new RuntimeException('boom'), $request)->getContent();

    // The fact-row MARKUP, not the bare words: with the trace on the page embeds a source excerpt of this
    // very test file, which contains the words "Reference" and the id as text — but HTML-escaped, so the
    // unescaped <dt>/<dd> pair can only come from the facts table.
    expect($html)->toContain('<dt>Reference</dt><dd>ref-5678-efgh</dd>')
        // With the trace on the message is shown, so the reassurance sentence is not — the fact row is
        // where the reference lives on this variant.
        ->not->toContain('quote reference')
        ->toContain('boom');
});

it('shows the W3C trace id in the Reference row and the correlation id beside it', function () {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/orders/42', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'corr-42']);
    $request->attributes->set(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');

    $error = ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($error->reference)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($error->correlationId)->toBe('corr-42')
        // The fact-row MARKUP, so what is asserted is the table and not a word the page happens to contain.
        ->and($html)->toContain('<dt>Reference</dt><dd>4bf92f3577b34da6a3ce929d0e0e4736</dd>')
        ->toContain('<dt>Correlation</dt><dd>corr-42</dd>')
        // The reference a person is asked to quote is the one a trace search can find.
        ->toContain('quote reference 4bf92f3577b34da6a3ce929d0e0e4736 if you report it');
});

it('shows no Correlation row when the reference already IS the correlation id', function () {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/orders/42', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'corr-42']);

    $error = ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($error->reference)->toBe('corr-42')
        ->and($error->correlationId)->toBe('corr-42')
        ->and($html)->toContain('<dt>Reference</dt><dd>corr-42</dd>')
        ->not->toContain('<dt>Correlation</dt>')
        // One id on the page, once: a second row holding the same value teaches a reader they are the same
        // thing, which is exactly what the two members exist to keep apart.
        ->and(substr_count($html, 'corr-42'))->toBe(2);
});

it('carries both ids on the detailed page too', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false);
    $request = Request::create('/orders/42', 'GET', server: ['HTTP_ACCEPT' => 'text/html', 'HTTP_X_CORRELATION_ID' => 'corr-77']);
    $request->attributes->set(TraceContext::TRACE_ID, 'aaaaaaaabbbbbbbbccccccccdddddddd');
    $renderer = new ErrorPageRenderer($settings, dirname(__DIR__, 4));

    $html = (string) $renderer->render(new RuntimeException('boom'), $request)->getContent();

    expect($html)->toContain('<dt>Reference</dt><dd>aaaaaaaabbbbbbbbccccccccdddddddd</dd>')
        ->toContain('<dt>Correlation</dt><dd>corr-77</dd>');
});

it('shortens every frame against the roots the trace reveals, not only against the base path', function () {
    // THE 10,108-PIXEL BUG, at the level that produced it. With a base path that matches nothing — which is
    // what a symlinked release, a bind mount and this very harness all look like — the old shorten() left
    // every one of 104 rows holding an absolute path, and each of them wrapped onto three lines.
    $settings = new ErrorPageSettings(trace: true);
    $error = ErrorReport::of(
        new RuntimeException('boom'),
        Request::create('/x'),
        $settings,
        '/nowhere-at-all',
        500,
        'Internal Server Error',
        '2026-01-01T00:00:00+00:00',
    );

    // The throw site is this file, under the repository the vendor frames reveal.
    expect($error->frames[0]->shortFile)->toBe('packages/web/tests/Error/ErrorPageTest.php')
        ->and($error->frames[0]->index)->toBe(0);

    $vendor = array_values(array_filter($error->frames, static fn ($f): bool => $f->vendor && $f->file !== ''));
    expect($vendor)->not->toBeEmpty();

    foreach ($vendor as $frame) {
        expect($frame->shortFile)->toStartWith('vendor/')
            ->and($frame->base())->not->toContain('/');

        // Dead until shorten() was fixed: package() parses vendor/{a}/{b} out of $shortFile, and every
        // $shortFile used to start with /Users/, so it answered null for all 104 frames. `vendor/bin/` is
        // Composer's shim directory and not a package directory — the test runner's own `vendor/bin/pest`
        // is a frame of this very trace — and answering `bin/pest` for it would name a package that does
        // not exist.
        if (! str_starts_with($frame->shortFile, 'vendor/bin/')) {
            expect($frame->package())->not->toBeNull();
        }
    }

    $packages = array_values(array_unique(array_filter(array_map(static fn ($f): ?string => $f->package(), $vendor))));

    expect($packages)->not->toBeEmpty();

    foreach ($packages as $package) {
        expect($package)->toMatch('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#');
    }
});

it('carries the verbs a 405 accepts, which the problem document already had and the page threw away', function () {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $e = new MethodNotAllowedHttpException(['POST', 'HEAD'], 'The GET method is not supported for route orders. Supported methods: POST, HEAD.');
    $error = ErrorReport::of($e, Request::create('/orders', 'GET'), $settings, dirname(__DIR__, 4), 405, 'Method Not Allowed', '2026-01-01T00:00:00+00:00');

    // HEAD is dropped where the sentence is built, not here: Symfony adds it beside every GET and no person
    // chooses it. It stays on the Allow header, where the standard wants it.
    expect($error->allowed)->toBe(['POST'])
        ->and($error->method)->toBe('GET');
});

it('carries the authored sentence for a sub-500 failure, so the page and the document say the same words', function () {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $business = new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND');
    $error = ErrorReport::of($business, Request::create('/orders/42'), $settings, dirname(__DIR__, 4), 404, 'Not Found', '2026-01-01T00:00:00+00:00');

    // An abort(404, '…') raises an HttpException, NOT a FireflyException — the taxonomy is not the test of
    // whether a sentence was authored, the status and the kind of throwable are.
    $aborted = ErrorReport::of(new NotFoundHttpException('No such tenant.'), Request::create('/t/9'), $settings, dirname(__DIR__, 4), 404, 'Not Found', '2026-01-01T00:00:00+00:00');

    // And a generic throwable's message is an accident — a table name, a bound value, a path on the server.
    $accident = ErrorReport::of(new RuntimeException('SQLSTATE[42S02]: no such table'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');

    expect($error->publicDetail)->toBe('Order 42 does not exist.')
        ->and($aborted->publicDetail)->toBe('No such tenant.')
        ->and($accident->publicDetail)->toBe('')
        // The router's own sentence is replaced by the product's, exactly as it is in problem+json.
        ->and(ErrorReport::of(new NotFoundHttpException('The route nope could not be found.'), Request::create('/nope'), $settings, dirname(__DIR__, 4), 404, 'Not Found', '2026-01-01T00:00:00+00:00')->publicDetail)
        ->toBe(ProblemMapper::NOTHING_HERE);
});

it('never publishes the 404 sentences LARAVEL generates, which name a model class and a primary key', function () {
    // Handler::prepareException() rewrites a ModelNotFoundException and a BackedEnumCaseNotFoundException
    // into `new NotFoundHttpException($e->getMessage(), $e)` before any renderable callback runs, so what
    // arrives here is an ordinary 404 carrying the FRAMEWORK's sentence and indistinguishable by class from
    // an author's abort(404, '…'). Only its SHAPE tells them apart. The model sentence is spelled as a
    // literal because firefly/web does not depend on illuminate/database — on this path the string IS the
    // interface — while the enum one is taken from the real class, which illuminate/routing supplies.
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $detail = static fn (string $message): string => ErrorReport::of(
        new NotFoundHttpException($message),
        Request::create('/orders/42'),
        $settings,
        dirname(__DIR__, 4),
        404,
        'Not Found',
        '2026-01-01T00:00:00+00:00',
    )->publicDetail;

    expect($detail('No query results for model [App\Models\Order] 42'))->toBe(ProblemMapper::NOTHING_HERE)
        // With no ids Laravel ends the sentence with a period instead; both spellings name the class.
        ->and($detail('No query results for model [App\Models\Order].'))->toBe(ProblemMapper::NOTHING_HERE)
        ->and($detail((new BackedEnumCaseNotFoundException('App\Enums\Status', 'pending'))->getMessage()))
        ->toBe(ProblemMapper::NOTHING_HERE)
        // And an author's own 404 still stands: this is a test of three generated shapes, not a gag on 404s.
        ->and($detail('No such tenant.'))->toBe('No such tenant.');
});

it('trims the stack to the configured budget BEFORE markup, and never trims your own frames away', function () {
    // The budget is an array_slice in the report, not a CSS trick in the page: a page that renders a hundred
    // frames and hides ninety of them has still built, escaped and shipped a hundred frames, and the DOM a
    // screen reader walks is still a hundred long.
    $settings = new ErrorPageSettings(trace: true, maxFrames: 6);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');

    $appKept = count(array_filter($error->frames, static fn ($f): bool => ! $f->vendor));
    $appTotal = $error->appFrameCount;

    expect($error->frames)->toHaveCount(6)
        ->and($error->frameCount)->toBeGreaterThan(6)
        // The counts describe the UNTRIMMED stack, so the page can say "6 of 104" honestly.
        ->and($appTotal)->toBeGreaterThan(0)
        ->and($appKept)->toBe(min($appTotal, 6))
        // Order is the stack's, still: the throw site is first whatever the budget dropped.
        ->and($error->frames[0]->call)->toBe('throw')
        ->and($error->frames[0]->index)->toBe(0);
});

it('clamps an absurd budget rather than trusting it', function () {
    $config = static fn (int $max): ErrorPageSettings => ErrorPageSettings::fromConfig(
        new Config(new Repository(['firefly' => ['web' => ['error-page' => ['max-frames' => $max]]]])),
    );

    expect($config(0)->maxFrames)->toBe(1)
        ->and($config(-7)->maxFrames)->toBe(1)
        ->and($config(100000)->maxFrames)->toBe(500)
        ->and(ErrorPageSettings::fromConfig(new Config(new Repository))->maxFrames)->toBe(40);
});

it('names the UNTRIMMED stack in the trace header, so a budgeted page cannot pass itself off as a whole one', function () {
    // The budget and the header are two halves of one promise. Trimming to six frames and then counting the
    // six is a label that was honest before `max-frames` existed and stops being honest the moment it does:
    // it says "2 of 6 in your code" for a stack of a hundred and marks nothing where ninety-four frames went.
    $trimmed = new ErrorPageSettings(trace: true, hints: false, maxFrames: 6);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $trimmed, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $trimmed);

    expect($error->frameCount)->toBeGreaterThan(6)
        ->and($html)->toContain('6 of '.$error->frameCount.' frames · '.$error->appFrameCount.' in your code')
        // One row per BUDGETED frame, still: the header names what was dropped, it does not smuggle it back.
        ->and(substr_count($html, '<li class="'))->toBe(6);

    // And when nothing was dropped the "of" is not written at all — "104 of 104" is a question a reader
    // should not have to answer to know they are looking at the whole stack.
    $whole = new ErrorPageSettings(trace: true, hints: false, maxFrames: 500);
    $all = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $whole, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');

    expect(ErrorPage::render($all, $whole))
        ->toContain($all->frameCount.' frames · '.$all->appFrameCount.' in your code')
        ->not->toContain(' of '.$all->frameCount.' frames');
});

it('answers an RFC 9457 `instance` that is root-relative, including at the site root', function () {
    // A relative reference resolves against the document's base URI, so `orders/42` served from /orders/42
    // identifies /orders/orders/42 — the member stops naming the occurrence it exists to name. The site
    // root is the case a naive '/'.$path would get wrong in the other direction, answering '//'.
    expect(ProblemMapper::instanceFor(Request::create('/orders/42')))->toBe('/orders/42')
        ->and(ProblemMapper::instanceFor(Request::create('/api/v1/accounts/42')))->toBe('/api/v1/accounts/42')
        ->and(ProblemMapper::instanceFor(Request::create('/')))->toBe('/')
        // A query string is not part of the path, and a trailing slash is normalised away by Laravel before
        // this ever sees it — both are the framework's answer, pinned here because the member depends on it.
        ->and(ProblemMapper::instanceFor(Request::create('/orders/42?include=lines')))->toBe('/orders/42')
        ->and(ProblemMapper::instanceFor(Request::create('/orders/')))->toBe('/orders');
});

it('renders one <li> per budgeted frame and no more, so the page cannot be a wall', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false, maxFrames: 8);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    $shownVendor = count(array_filter($error->frames, static fn ($f): bool => $f->vendor));
    $allVendor = $error->frameCount - $error->appFrameCount;

    expect(substr_count($html, '<li class="own">') + substr_count($html, '<li class="vendor">'))->toBe(8)
        // And the header is honest about what it dropped, rather than quietly showing eight of a hundred.
        ->toBeLessThan($error->frameCount)
        ->and($html)->toContain('8 of '.$error->frameCount.' frames')
        ->toContain($error->appFrameCount.' in your code')
        // THE DISCLOSURE COUNTS THE SAME UNTRIMMED STACK THE HEADER DOES. Its label is the dependency
        // frames in the whole stack, not the handful that survived the budget — `count($vendor)` there
        // would read "2 frames in your dependencies" over a trace with fifty of them — and the `.dn` note
        // beside it is what makes that honest rather than merely large: it says how many of those fifty
        // are actually in the list below. Both halves are asserted, because a label that carries whatever
        // number it is given passes a `toContain('frames in your dependencies')` either way.
        ->and($allVendor)->toBeGreaterThan($shownVendor)
        ->and($html)->toContain('<span class="dsum">'.$allVendor.' frames in your dependencies</span>')
        ->toContain('<span class="dn">'.$shownVendor.' shown</span>');
});

it('puts your frames in the list and your dependencies behind one disclosure', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false, maxFrames: 60);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    $own = strpos($html, '<li class="own">');
    $deps = strpos($html, '<details class="deps">');

    expect($own)->toBeInt()
        ->and($deps)->toBeInt()
        // Your code first, always: the vendor set opens BELOW it and starts closed. Stated as a boolean
        // rather than through toBeLessThan(): strpos() answers int|false, an expectation does not narrow
        // the variable it was given, and comparing a possible false at level max is an error, not a style.
        ->and($own !== false && $deps !== false && $own < $deps)->toBeTrue()
        ->and($html)->toContain('<span class="dsum">'.($error->frameCount - $error->appFrameCount).' frames in your dependencies</span>')
        // And with nothing trimmed the note is not written at all, for the reason the header leaves out
        // its "of": "32 shown" beside "32 frames in your dependencies" is a question a reader should not
        // have to answer to know they are looking at the whole vendor set. The budget is stated here
        // rather than assumed — a Pest stack longer than it would silently turn this into the other case.
        ->and($error->frameCount)->toBeLessThanOrEqual(60)
        ->and($html)->not->toContain('class="dn"')
        ->and(substr_count($html, '<details class="deps"'))->toBe(1)
        // A closed <details> is a real control with real keyboard behaviour. The alternative a judge
        // measured — a checkbox in one div and a `~` selector reaching for a sibling of its PARENT — matches
        // nothing at all, and makes every vendor frame permanently unreachable with scripts on or off.
        ->and($html)->not->toContain('~ .tw')
        ->not->toContain('type="checkbox"');
});

it('opens exactly one frame, and makes the others exclusive rather than merely closed', function () {
    // The throwable is built one call DEEPER than the test closure on purpose. Pest invokes that closure
    // from its own vendor code, so a throwable constructed inline has exactly one frame of the
    // application's — nothing to be exclusive WITH — and the property under test is precisely what happens
    // from the second such frame onward.
    $deeper = static fn (): RuntimeException => new RuntimeException('boom');

    $settings = new ErrorPageSettings(trace: true, hints: false);
    $error = ErrorReport::of($deeper(), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    $disclosable = count(array_filter($error->frames, static fn ($f): bool => $f->excerpt !== []));

    expect($disclosable)->toBeGreaterThan(1)
        // `name=` is HTML's own exclusive accordion: opening one closes the rest, with no script and no CSS.
        ->and(substr_count($html, '<details name="firefly-frame" open>'))->toBe(1)
        ->and(substr_count($html, '<details name="firefly-frame">'))->toBe($disclosable - 1)
        ->and(substr_count($html, '<details name="firefly-frame">'))->toBeGreaterThan(0);
});

it('draws a frame as one line: a directory that may be clipped and a file name that never is', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($html)->toContain('<span class="base">ErrorPageTest.php</span>')
        ->toContain('<span class="dir">packages/web/tests/Error/</span>')
        // The row is a nowrap flex line and the two shrinkable spans are ellipsised. The old rule wrapped
        // every row onto three lines, which is what a 10,108-pixel page is made of.
        ->toContain('.frames .row,.frames summary{display:flex')
        ->toContain('flex-wrap:nowrap')
        ->toContain('.frames .base{')
        ->and($html)->not->toContain('.frames .where');
});

it('labels a dependency frame with its package, which was dead until the paths were shortened', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false, maxFrames: 60);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($html)->toMatch('#<span class="pkg">[a-z0-9._-]+/[a-z0-9._-]+</span>#');
});

it('gives the method name a span of its own, so the token a vendor frame is known by is never the clipped end', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false, maxFrames: 60);
    // A Pest run reaches this closure through its own vendor code, so the trace really is a deep vendor
    // stack — the shape the row was designed for and the one that exposed the bug.
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    $vendor = count(array_filter($error->frames, static fn ($f): bool => $f->vendor));

    expect($vendor)->toBeGreaterThan(5)
        // `.call` used to be one ellipsised span, and a call is `Class->method()`: the clip took the METHOD
        // NAME and kept the namespace every Illuminate frame shares. The pair is now the path's pair.
        ->and($html)->toMatch('#<span class="cls">[^<]+</span><span class="fn">(-&gt;|::)[A-Za-z_]#')
        // A phone drops the qualifier whole rather than shortening the method name.
        ->toContain('.frames .call .cls{display:none}')
        // The old single span, with the whole call inside the shrinkable box, must not come back.
        ->not->toContain('.frames .call{font-family:var(--mono);font-size:12px;color:var(--ink-3);margin-left:auto;min-width:0;flex:0 1 auto;overflow:hidden')
        // The synthetic throw frame has no qualifier at all, so the slot is simply not printed.
        ->and($html)->toContain('<span class="fn">throw</span>');
});

it('ranks what a narrow row gives up, and takes it inside the row rather than at the panel edge', function () {
    $settings = new ErrorPageSettings(trace: true, hints: false, maxFrames: 60);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    // `.fn` WAS `flex:none`, on the theory that a span which cannot shrink keeps its text. It does not: it
    // keeps its WIDTH, its own `text-overflow` never gets a box narrower than its glyphs to draw an
    // ellipsis in, and the glyphs run out of the row to be cut by `.panel{overflow:hidden}` with nothing
    // marking the cut. Measured in Chrome at 375px over a 35-frame Laravel trace, 34 of 35 rows painted
    // their method name up to 138px past the panel and `->whereHasMorphRelationship` arrived as `->wh`.
    expect($html)
        // The rank is a shrink FACTOR, not a refusal to shrink: .dir and .cls at 100, .fn at 1, so flexbox
        // spends both discardable spans to nothing before it takes a character off the method name.
        ->toContain('.frames .dir{font-family:var(--mono);font-size:12.5px;color:var(--ink-2);min-width:0;flex:0 100 auto;overflow:hidden;text-overflow:ellipsis}')
        ->toContain('.frames .call .cls{min-width:0;flex:0 100 auto;overflow:hidden;text-overflow:ellipsis}')
        ->toContain('.frames .call .fn{min-width:0;flex:0 1 auto;max-width:24em;overflow:hidden;text-overflow:ellipsis}')
        // And the row contains its own overflow, so nothing is ever cut by the panel instead.
        ->toContain('display:flex;align-items:baseline;overflow:hidden}')
        ->not->toContain('.frames .call .fn{flex:none')
        // A PHONE DOES NOT SPEND THE LAST RANK AT ALL. At 375px a row has 295px and a real Laravel file
        // name — `AddQueuedCookiesToResponse.php`, which never shortens — is 226 of them, so ranked
        // shrinking alone ends with 23 of 35 method names cut and two rendered at zero width. The row wraps
        // instead and the call takes a line of its own; the directory goes with `.pkg` and `.cls`, which is
        // what holds the wrapped row to two lines. Nothing wraps INSIDE a span — that was the pre-wave rule
        // at 87px a row — so the break can only fall between two whole tokens.
        ->toContain('.frames .row,.frames summary{flex-wrap:wrap}')
        ->toContain('.frames .dir{display:none}')
        ->toContain('.frames .call{margin-left:0}')
        // With a line to itself the call keeps the 24em guard it has everywhere: the phone's old 14em cap
        // was cutting `->sendRequestThroughRouter` for room it no longer needs.
        ->not->toContain('max-width:14em')
        // The base rule stays nowrap; only the phone block relaxes it, which is what keeps a desktop row
        // on one line at every panel width from 540px up (measured: 0 rows past the panel, 35 of 35 on one
        // line). THE GEOMETRY ITSELF IS PINNED IN tests/Browser/ErrorPagesDebugTest.php, not here, by
        // TRACE_CALLS_INSIDE_PANEL: `assertSee` reads text content, which is identical whether a span is
        // drawn whole or clipped in half at the panel's edge, so the phone-width case measures every
        // rendered `.fn` right edge against its `.panel` right edge instead.
        ->toContain('flex-wrap:nowrap;white-space:nowrap}');
});

it('keeps every text token above 4.5:1, and the focus ring above 3:1, on every ground each is drawn on', function () {
    // --ink-3 was #8d95a1 in light (2.80:1 on the page ground, 3.02:1 on a panel) and #6c7883 in dark
    // (3.76:1 on the inset panel). It carries the call, the frame index and the panel counts — small text a
    // reader is asked to compare, which is the last place to spend contrast.
    $html = ErrorPage::render(
        ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), new ErrorPageSettings(trace: true), dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00'),
        new ErrorPageSettings(trace: true),
    );

    $ratio = static function (string $a, string $b): float {
        $lum = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channel = static fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)) / 255)
                + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)) / 255)
                + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)) / 255);
        };

        [$hi, $lo] = $lum($a) >= $lum($b) ? [$lum($a), $lum($b)] : [$lum($b), $lum($a)];

        return ($hi + 0.05) / ($lo + 0.05);
    };

    expect($html)->toContain('--ink-3:#696f7d')
        ->toContain('--ink-3:#828e99')
        // Light: page ground, panel, inset panel.
        ->and($ratio('#696f7d', '#f7f6f3'))->toBeGreaterThan(4.5)
        ->and($ratio('#696f7d', '#ffffff'))->toBeGreaterThan(4.5)
        ->and($ratio('#696f7d', '#faf9f6'))->toBeGreaterThan(4.5)
        // Dark: the same three.
        ->and($ratio('#828e99', '#0f1214'))->toBeGreaterThan(4.5)
        ->and($ratio('#828e99', '#15191c'))->toBeGreaterThan(4.5)
        ->and($ratio('#828e99', '#181d21'))->toBeGreaterThan(4.5);

    // THE FOCUS RING, which is not text and so is measured against SC 1.4.11's 3:1, not 4.5:1. It is drawn
    // on two grounds: a frame's summary on --panel, and the dependency disclosure — the one control a
    // keyboard reader MUST operate to reach the frames behind it — on --panel-2. In --brand those measured
    // 3.01:1 and 2.86:1, so the ring on the control that matters most was the one below the floor.
    expect($html)->toContain('--brand-ink:#a1520a')
        ->toContain('.frames summary:focus-visible{outline:2px solid var(--brand-ink)')
        ->toContain('.deps>summary:focus-visible{outline:2px solid var(--brand-ink)')
        // Pinned as a string so the token cannot silently go back to the shape one that fails.
        ->not->toContain('outline:2px solid var(--brand)')
        ->and($ratio('#a1520a', '#ffffff'))->toBeGreaterThan(3.0)
        ->and($ratio('#a1520a', '#faf9f6'))->toBeGreaterThan(3.0)
        // Dark leaves the ring on --brand's own value; both tokens are #ff9d3c there.
        ->and($ratio('#ff9d3c', '#15191c'))->toBeGreaterThan(3.0)
        ->and($ratio('#ff9d3c', '#181d21'))->toBeGreaterThan(3.0)
        // And the value that was failing is recorded as failing, so the swap cannot be undone by accident.
        ->and($ratio('#e07a17', '#faf9f6'))->toBeLessThan(3.0);
});

it('shows the frames when not one of them is yours, instead of a heading over a closed disclosure', function () {
    // THE STACK THIS PINS IS NOT A HYPOTHETICAL. `ErrorFrame::$vendor` is decided by `/vendor/` in the
    // path, so a stack has no application frame whenever nothing application-owned is on it: a routing
    // miss, a 405, a container or bootstrap throw — everything raised BEFORE application code runs — under
    // any deployment whose front controller is itself a dependency, which is Octane, FrankenPHP worker mode
    // and Vapor. The split then has nothing to put in its list, and with the disclosure hardcoded closed
    // the trace panel came out as a heading over one collapsed row with not a single frame in sight: worse
    // than the interleaved trace the split replaced, and a flat contradiction of "nothing is hidden".
    //
    // WHY THE REPORT IS BUILT BY HAND. This repository cannot produce the shape through ErrorReport::of():
    // a PHP process always has its entry script at the bottom of the stack, and here that script is a test
    // file under `packages/` or `tests/` — application code by the same `/vendor/` rule. Measured against
    // the wave's own browser harness, even its routing-miss 404 reports `40 of 98 frames · 9 in your code`,
    // because the in-process server is driven from `tests/Browser/`. So the renderer is fed the report
    // Octane hands it instead, which is the unit that has the decision: the page is a pure function of the
    // report, and the report shape is the one the pipeline genuinely builds off the floor of a vendored
    // front controller.
    $class = new ReflectionClass(ErrorReport::class);
    $constructor = $class->getConstructor();

    expect($constructor)->not->toBeNull();

    $report = $class->newInstanceWithoutConstructor();
    $constructor?->invokeArgs($report, [
        'status' => 404,
        'reason' => 'Not Found',
        'code' => 'ROUTE_NOT_FOUND',
        'category' => 'business',
        'severity' => 'warning',
        'method' => 'GET',
        'path' => '/does-not-exist',
        'timestamp' => '2026-01-01T00:00:00+00:00',
        'detailed' => true,
        'exceptionClass' => 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
        'message' => 'The route does-not-exist could not be found.',
        'location' => 'vendor/laravel/framework/src/Illuminate/Routing/AbstractRouteCollection.php:44',
        'frames' => [
            new ErrorFrame('/srv/vendor/laravel/framework/src/Illuminate/Routing/AbstractRouteCollection.php', 'vendor/laravel/framework/src/Illuminate/Routing/AbstractRouteCollection.php', 44, 'throw', true, [], 0),
            new ErrorFrame('/srv/vendor/laravel/framework/src/Illuminate/Routing/Router.php', 'vendor/laravel/framework/src/Illuminate/Routing/Router.php', 731, 'Illuminate\Routing\AbstractRouteCollection->handleMatchedRoute()', true, [], 1),
            new ErrorFrame('/srv/vendor/laravel/octane/bin/swoole-server', 'vendor/laravel/octane/bin/swoole-server', 21, 'Laravel\Octane\Worker->handle()', true, [], 2),
        ],
        'frameCount' => 3,
        'appFrameCount' => 0,
    ]);

    $html = ErrorPage::render($report, new ErrorPageSettings(trace: true, hints: false));

    expect($html)
        // A reader meets three frames, not a closed row: the disclosure is open because it is the panel.
        ->toContain('<details class="deps" open>')
        ->toContain('<span class="dsum">3 frames in your dependencies</span>')
        ->and(substr_count($html, '<li class="vendor">'))->toBe(3)
        ->and($html)->toContain('<span class="base">AbstractRouteCollection.php</span>')
        ->toContain('<span class="base">swoole-server</span>')
        // The header still counts the stack, and still says none of it is the application's.
        ->toContain('3 frames · 0 in your code')
        // And the one place a closed <details> and an open one sit differently: the heading above it
        // already draws a border-bottom, so the disclosure drops its own border-top when it follows one.
        ->toContain('.panel h2+.deps{border-top:0}');
});

it('leaves the dependency disclosure closed whenever there are frames above it to read', function () {
    // The other side of the branch, and the one that must not move: when the split has a list, the
    // dependency set is the stack the reader came THROUGH and starts collapsed. Driven through
    // ErrorReport::of() rather than by hand, because this shape is the one a real run produces.
    $settings = new ErrorPageSettings(trace: true, hints: false, maxFrames: 60);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($error->appFrameCount)->toBeGreaterThan(0)
        ->and($html)->toContain('<li class="own">')
        ->toContain('<details class="deps">')
        ->not->toContain('<details class="deps" open>');
});
