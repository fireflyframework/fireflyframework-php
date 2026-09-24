<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Kernel\Exception\Security\AuthenticationException;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    // The application's OWN sentence is published, and always was — by problem+json, for the same failure,
    // written for the caller. The page withholding it was the inconsistency: a person reading the page and
    // a client reading the document were told two different things about one error. What stays withheld is
    // everything that is not a sentence somebody wrote: the class, the file, the trace, the page's own
    // advice about turning the trace on. `firefly.web.error-page.authored-detail => false` restores the
    // status-and-code-only page for a deployment that wants it.
    expect($html)->toContain('Order 42 does not exist.')
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
        // The prose points at the Reference cell instead of repeating the id into it: the id was printed
        // TWICE on every production page, and neither copy could be copied.
        ->toContain('quote the reference below if you report it')
        ->toContain('<dt>Reference</dt><dd>ref-1234-abcd</dd>')
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
        ->not->toContain('quote the reference below')
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
        // The reference a person is asked to quote is the one a trace search can find, and it is named
        // once, in the cell that can be selected in a single click.
        ->toContain('quote the reference below if you report it');
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
        // One id on the page, once: the cell, and the copy button's data-ref that reads it. It used to be
        // the cell and a second copy spelled into prose, which teaches a reader to retype rather than
        // select — and printed the same value in two places with no affordance on either.
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
        // The copy button's ring is the third one, and it is measured on the same two grounds: the
        // button sits on --panel-2 inside a --panel cell, which is exactly where --brand fell short.
        ->toContain('.copy:focus-visible{outline:2px solid var(--brand-ink)')
        // The action row's ring is the fourth, and it is drawn on the page ground the header sits on —
        // #e07a17 measures 2.95:1 there, so the row that gives a keyboard reader the way off this page
        // would have shipped the one ring below the floor.
        ->toContain('.act:focus-visible{outline:2px solid var(--brand-ink)')
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
        'query' => '',
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

it('draws the fact grid on the panel ground, so a ragged last row is not a dead beige cell', function () {
    // The grid is `gap:1px` over a coloured container, so the container shows through between cells — and
    // through the WHOLE trailing area whenever the fact count is not a multiple of the (responsive, and
    // therefore unknowable) column count. Six facts in four columns is two dead cells, which is what every
    // production screenshot shows. The rules are drawn by each cell instead, outset and clipped.
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $error = ErrorReport::of(new RuntimeException('boom'), Request::create('/x'), $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($html)->toContain('.facts{display:grid')
        ->toContain('background:var(--panel)')
        ->toContain('.facts>div{')
        ->toContain('box-shadow:-1px -1px 0 var(--line)')
        ->and($html)->not->toMatch('#\.facts\{[^}]*background:var\(--line\)#')
        ->not->toMatch('#\.facts\{[^}]*gap:1px#');
});

it('prints the reference once, as something a reader can select in one gesture', function () {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/orders/42', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'ref-once-1234']);
    $error = ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($html)->toContain('<div class="fact-ref"><dt>Reference</dt><dd>ref-once-1234</dd>')
        ->toContain('Quote this if you report the problem.')
        // The sentence points AT the cell rather than repeating the id into prose.
        ->toContain('quote the reference below if you report it')
        ->not->toContain('quote reference ref-once-1234')
        // `user-select:all` is the affordance that needs nothing: one click takes the whole id, with
        // JavaScript off, in a container's minimal browser, in a screenshot tool's headless Chromium.
        ->toContain('.fact-ref dd:first-of-type{user-select:all');
});

it('ships the copy button hidden and reveals it from the same script that gives it behaviour', function () {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/x', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'ref-copy-99']);
    $error = ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($html)->toContain('<button type="button" class="copy" hidden data-ref="ref-copy-99">Copy</button>')
        ->toContain('navigator.clipboard')
        // A control that does nothing is worse than no control: with scripts off, with a CSP that refuses
        // an inline script, or on a plain-http origin where navigator.clipboard is undefined, the button
        // stays hidden and the select-all cell is still there.
        //
        // AND THE CLICK HAS A REJECTION ARM, because those three are the modes the REVEAL can see.
        // writeText() rejects with navigator.clipboard present and the guard already passed — an unfocused
        // document (an ordinary DOMException, and the common one), a denied `clipboard-write` permission, an
        // embedding Permissions-Policy that omits it — and in every one of those the button has already been
        // shown. Without this the click does nothing at all, the label stays "Copy", and the quietest page
        // in the framework writes an unhandled promise rejection to the console.
        ->toContain('.catch(function(){b.textContent="Copy failed"})')
        ->and(substr_count($html, '<script>'))->toBe(1);

    $off = new ErrorPageSettings(trace: false, hints: false, copyButton: false);
    $plain = ErrorPage::render(ErrorReport::of(new RuntimeException('boom'), $request, $off, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00'), $off);

    expect($plain)->not->toContain('<script>')
        ->not->toContain('class="copy"')
        ->toContain('<dd>ref-copy-99</dd>');
});

it('escapes a reference into the data attribute as well as the cell', function () {
    // The reference comes off a request header. It is echoed twice now, so it is escaped twice.
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/x', 'GET', server: ['HTTP_X_CORRELATION_ID' => '"><script>alert(1)</script>']);
    $error = ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;')
        ->toContain('data-ref="&quot;&gt;&lt;script&gt;');
});

it('gives a 404 the same single reference cell, so one page teaches the other', function () {
    // CorrelationIdFilter mints an id when the request carried none, so every rendered page has a reference
    // — but only the 5xx sentence names it. The CELL is the constant: whatever the status, the id is in one
    // place, selectable, with the same words under it.
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/nope', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'ref-404-aa']);
    $html = ErrorPage::render(
        ErrorReport::of(new NotFoundHttpException, $request, $settings, dirname(__DIR__, 4), 404, 'Not Found', '2026-01-01T00:00:00+00:00'),
        $settings,
    );

    expect($html)->toContain('<div class="fact-ref"><dt>Reference</dt><dd>ref-404-aa</dd>')
        ->toContain('Quote this if you report the problem.')
        // A 404 is not "something went wrong on our side", so that sentence is not on it.
        ->not->toContain('quote the reference below');
});

it('keeps the id shared with the problem document and lets the sentence diverge on purpose', function () {
    // The 5xx lede used to be ProblemMapper::OPAQUE_WITH_REFERENCE's wording, id and all, so a ticket read
    // the same whichever surface the failure was seen on. It is not any more — and that is a decision, not
    // drift: naming the id in prose AND in the cell put one uuid on the page twice with no way to copy
    // either. A problem document has no cell to point at, so it keeps its id inline and keeps its wording.
    //
    // What survives as the cross-surface invariant is the ID, not the SENTENCE, and this pins both halves so
    // neither can be re-decided in silence. The page's clause is read off a real render rather than typed,
    // so the day the lede changes this test names the sentence that has to change with it.
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $request = Request::create('/x', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'ref-split-7']);
    $error = ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00');
    $html = ErrorPage::render($error, $settings);

    if (preg_match('/It has been logged; ([^.<]+)\./', $html, $matched) !== 1) {
        throw new RuntimeException('The production 5xx lede no longer carries an "It has been logged; …" clause for this test to read.');
    }
    $clause = $matched[1];
    $document = sprintf(ProblemMapper::OPAQUE_WITH_REFERENCE, $error->reference);

    expect($error->reference)->toBe('ref-split-7')
        // The id is on both surfaces: in the page's cell, inline in the document.
        ->and($html)->toContain('<dt>Reference</dt><dd>ref-split-7</dd>')
        ->and($document)->toContain('ref-split-7')
        // The sentence is not. The page's clause points at the cell and names no id; the document's names
        // it, and neither is a substring of the other.
        ->and($clause)->not->toContain('ref-split-7')
        ->and($document)->not->toContain($clause);
});

$page = static fn (Throwable $e, ErrorPageSettings $settings, int $status, string $reason, string $method = 'GET', string $path = '/orders/42'): string => ErrorPage::render(
    ErrorReport::of($e, Request::create($path, $method), $settings, dirname(__DIR__, 4), $status, $reason, '2026-01-01T00:00:00+00:00'),
    $settings,
);

it('offers a 401 the way back in, and only when one was configured', function () use ($page) {
    $configured = new ErrorPageSettings(trace: false, hints: false, home: '/', signIn: '/login');
    $bare = new ErrorPageSettings(trace: false, hints: false, home: '', signIn: '');

    $html = $page(new AuthenticationException('Authentication is required to access this resource.', 'AUTHENTICATION_FAILED'), $configured, 401, 'Unauthorized');

    expect($html)->toContain('<nav class="acts" aria-label="What you can do next">')
        ->toContain('<a class="act primary" href="/login">Sign in</a>')
        ->toContain('<a class="act" href="/">Go home</a>')
        // Nothing is invented: with no destination configured the page offers none rather than guessing a
        // route name that may not exist.
        ->and($page(new AuthenticationException('nope', 'AUTHENTICATION_FAILED'), $bare, 401, 'Unauthorized'))
        ->not->toContain('class="acts"');
});

it('offers a 5xx the one action its reader can actually take: ask again', function () use ($page) {
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/', support: 'https://support.example.test');

    expect($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'GET', '/orders/42'))
        // A plain link to the request that failed: it re-issues a GET, it works with scripts off, and the
        // address it names came back UNCHANGED from ErrorPageSettings::url(), the same guard every
        // configured href on this page passed. See the two tests below for the halves of that sentence.
        ->toContain('<a class="act primary" href="/orders/42">Try again</a>')
        ->toContain('<a class="act" href="/">Go home</a>')
        ->toContain('<a class="act" href="https://support.example.test">Contact support</a>');
});

it('offers a 404 the way home and nothing it cannot deliver', function () use ($page) {
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/', signIn: '/login');

    expect($page(new NotFoundHttpException, $settings, 404, 'Not Found'))
        ->toContain('<a class="act primary" href="/">Go home</a>')
        // Sign-in is the 401's answer, not every page's: offering it here says the reader was refused when
        // they were not.
        ->not->toContain('Sign in')
        ->not->toContain('Try again');
});

it('names the verbs a 405 accepts instead of shrugging at the caller', function () use ($page) {
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/');
    $one = new MethodNotAllowedHttpException(['POST', 'HEAD'], 'The GET method is not supported for route orders.');
    $several = new MethodNotAllowedHttpException(['PUT', 'PATCH', 'DELETE']);

    expect($page($one, $settings, 405, 'Method Not Allowed', 'GET', '/orders'))
        ->toContain('That address does not accept a GET request. It accepts POST.')
        ->and($page($several, $settings, 405, 'Method Not Allowed', 'POST', '/orders/1'))
        ->toContain('That address does not accept a POST request. It accepts PUT, PATCH or DELETE.')
        // And a 405 has nothing to retry: the verb is wrong, not the moment.
        ->not->toContain('Try again');
});

it('uses the sentence the problem document publishes, so one failure reads one way', function () use ($page) {
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $business = new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND');

    expect($page($business, $settings, 404, 'Not Found'))
        ->toContain('Order 42 does not exist.')
        ->and($page(new NotFoundHttpException('No such tenant.'), $settings, 404, 'Not Found'))
        ->toContain('No such tenant.')
        // The router's own sentence is still replaced by the product's, exactly as it is on the wire.
        ->and($page(new NotFoundHttpException('The route nope could not be found.'), $settings, 404, 'Not Found'))
        ->toContain(ProblemMapper::NOTHING_HERE)
        ->not->toContain('could not be found');
});

it('still withholds a generic throwable\'s message, which is an accident and not a sentence', function () use ($page) {
    // The gate is the STATUS and the kind of throwable, never the presence of a message. A QueryException
    // stringifies its SQL and its bindings; that is what `publicDetail` exists to keep off this page while
    // letting "Order 42 does not exist." through.
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $leak = new RuntimeException("SQLSTATE[42S02]: no such table (SQL: select * from users where email = 'ada@example.test')");

    expect($page($leak, $settings, 500, 'Internal Server Error'))
        ->not->toContain('SQLSTATE')
        ->not->toContain('ada@example.test')
        ->toContain('Something went wrong on our side.');
});

it('lets an operator switch every action off, and never builds an href it did not check', function () use ($page) {
    $off = new ErrorPageSettings(trace: false, hints: false, home: '/', signIn: '/login', actions: false);
    // A hostile value never reaches the settings object at all (see ErrorPageSecurityTest); this proves the
    // page prints exactly what the settings hold and adds no fallback of its own.
    $empty = new ErrorPageSettings(trace: false, hints: false, home: '', signIn: '', support: '');

    expect($page(new NotFoundHttpException, $off, 404, 'Not Found'))->not->toContain('class="acts"')
        ->and($page(new NotFoundHttpException, $empty, 404, 'Not Found'))->not->toContain('class="acts"')
        ->and($page(new NotFoundHttpException, $empty, 404, 'Not Found'))->not->toContain('href="javascript:');
});

it('re-issues the request that failed, query string and all', function () use ($page) {
    // "AGAIN" IS A PROMISE ABOUT THE REQUEST. Laravel's `path()` answers `search` for /search?q=foo&page=2,
    // so a link built from it alone hands a reader whose SEARCH failed an empty one and asks them to retype
    // what they already typed — a loss nothing on the page admits to, because the page looks right.
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/');

    expect($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'GET', '/search?q=foo&page=2'))
        // The `&` is `&amp;` because that is how an ampersand is spelled inside an attribute value, and the
        // pairs are sorted because Symfony's getQueryString() normalises them. Same request either way.
        ->toContain('<a class="act primary" href="/search?page=2&amp;q=foo">Try again</a>')
        // A path with no query keeps the bare path — no trailing '?' on the overwhelming majority of links.
        ->and($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'GET', '/orders/42'))
        ->toContain('href="/orders/42">Try again</a>')
        ->not->toContain('href="/orders/42?"');
});

it('cannot be talked out of the href by a query string, however it is spelled', function () use ($page) {
    // The query is the one part of this href that comes from the CALLER, so it is the one part worth
    // proving cannot end the attribute it sits in. getQueryString() percent-encodes to RFC 3986 before the
    // page escapes anything: `"` is already %22 and `<` is %3C, so there is no quote left to close on.
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '');

    $html = $page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'GET', '/search?q="><script>alert(1)</script>&page=2');

    expect($html)->toContain('href="/search?page=2&amp;q=%22%3E%3Cscript%3Ealert%281%29%3C%2Fscript%3E">')
        ->not->toContain('<script>alert(1)</script>')
        // And the fact grid still states the PATH alone: a query string is where a token or a search a
        // person would rather not screenshot tends to live, and the grid offers no action to justify it.
        ->toContain('<dd>GET /search</dd>');
});

it('keeps the 405 verbs when authored detail is off, because they are the framework\'s own', function () use ($page) {
    // THE KEY GOVERNS DISCLOSURE, AND THERE IS NONE HERE. `authored-detail` decides whether the sentence an
    // APPLICATION wrote reaches a person. Nothing of the application's is in the verb sentence: the verbs
    // come off the `Allow` header the ROUTER put on its own exception, the page writes the words, and the
    // same list is the `allowed` member of the document published for the same failure. Pinned because two
    // docblocks and the config reference now promise an operator exactly this, and because the behaviour
    // rests on nothing louder than the ORDER of two branches in lede().
    $off = new ErrorPageSettings(trace: false, hints: false, authoredDetail: false);

    expect($page(new MethodNotAllowedHttpException(['POST', 'HEAD']), $off, 405, 'Method Not Allowed', 'GET', '/orders'))
        ->toContain('That address does not accept a GET request. It accepts POST.')
        // Every other status DOES go back to the reassurance with the key off, which is the status-and-code
        // page the key exists to offer.
        ->and($page(new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND'), $off, 404, 'Not Found'))
        ->toContain('That page does not exist.')
        ->not->toContain('Order 42 does not exist.');
});

it('does not offer to re-issue a request a link cannot re-issue', function () use ($page) {
    // A LINK IS A GET, WHATEVER IT CLAIMS TO REPEAT. "Try again" was offered on every status >= 500 and its
    // href is a plain `<a>`, so a POST that 500s was handed a link that carries the address and the query
    // and drops the verb and the body — on a POST-only route it lands the reader on this wave's own 405
    // page, and on a route that answers both verbs it sends a DIFFERENT request under a label that says
    // "again". The two offers that remain are the two that are true for a failed POST.
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/', support: 'https://support.example.test');

    expect($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'POST', '/orders'))
        ->toContain('class="acts"')
        ->toContain('<a class="act primary" href="/">Go home</a>')
        ->toContain('<a class="act" href="https://support.example.test">Contact support</a>')
        ->not->toContain('Try again')
        // A HEAD is a GET without a body, so it is the one other verb a link repeats faithfully.
        ->and($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'HEAD', '/orders'))
        ->toContain('>Try again</a>')
        // And the verbs that carry a body are refused one by one rather than by a rule about POST alone.
        ->and($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'PUT', '/orders/42'))
        ->not->toContain('Try again')
        ->and($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'PATCH', '/orders/42'))
        ->not->toContain('Try again')
        ->and($page(new RuntimeException('boom'), $settings, 500, 'Internal Server Error', 'DELETE', '/orders/42'))
        ->not->toContain('Try again');
});

it('puts the request\'s own address through the guard every other href on the page passed', function () {
    // `/\host` IS AN AUTHORITY WEARING A PATH'S CLOTHES, and it reaches `path()` intact. Symfony refuses a
    // backslash in a request target only inside Request::create(), so this request is built the way a real
    // one is — from a raw $_SERVER array through prepareRequestUri(), which neither refuses nor normalises
    // it. `path()` answers `\evil.example`; '/'.ltrim(…) makes `/\evil.example`; and for a special scheme
    // the URL parser treats `\` exactly like `/`, so a browser resolves that as https://evil.example/. It
    // would have been the PRIMARY action on the page, during the incident — a 5xx on an arbitrary path —
    // that is precisely when a reader clicks "Try again". ErrorPageSettings::url() has refused this
    // spelling for every configured href since the row existed; retry() asks it the same question now.
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/');

    $render = static fn (string $requestUri): string => ErrorPage::render(
        ErrorReport::of(
            new RuntimeException('boom'),
            new Request([], [], [], [], [], [
                'REQUEST_URI' => $requestUri,
                'REQUEST_METHOD' => 'GET',
                'SERVER_NAME' => 'app.test',
                'HTTP_HOST' => 'app.test',
                'SERVER_PORT' => '80',
            ]),
            $settings,
            dirname(__DIR__, 4),
            500,
            'Internal Server Error',
            '2026-01-01T00:00:00+00:00',
        ),
        $settings,
    );

    // The address is asserted absent from every HREF, not from the page: the fact grid legitimately states
    // "GET /\evil.example", because that is what the request WAS and a statement is not a destination.
    expect($render('/\evil.example'))
        ->not->toContain('href="/\\')
        // No offer at all rather than a fallback dressed as one: a link the page cannot spell truthfully is
        // worse than a row with one fewer button on it, and "Go home" already says where home is.
        ->not->toContain('Try again')
        ->toContain('<a class="act primary" href="/">Go home</a>')
        // A tab, an LF or a CR is DELETED by the parser wherever it sits, so `/<TAB>/evil.example` IS
        // `//evil.example` by the time anything reads it. Same guard, same refusal.
        ->and($render("/\t/evil.example"))
        ->not->toContain("href=\"/\t")
        ->not->toContain('Try again')
        // And an ordinary address is still offered, so the guard is a filter and not an off switch.
        ->and($render('/orders/42'))
        ->toContain('<a class="act primary" href="/orders/42">Try again</a>');
});

it('keeps the base path a front controller is served under, so the retry is the request that failed', function () {
    // `path()` IS `getPathInfo()`, AND IT IS BASE-URL-STRIPPED BY DESIGN: the router matches on the path
    // info, so the front controller's own prefix is deliberately not in it. Built into an href on its own
    // it names an address the deployment never serves — which makes the PRIMARY action on every 5xx page a
    // 404, or a step off the application, on every box served under a base path. The family settled this
    // spelling before this row existed (`$request->getBaseUrl().$path`, in LoginPageAction and the two
    // OAuth2 link builders, pinned there by a case with this same name); retry() reuses it rather than
    // inventing a second one.
    //
    // BUILT FROM A RAW $_SERVER ARRAY, like the guard case above, because a base URL exists only when
    // SCRIPT_NAME is a prefix of REQUEST_URI — which is what a front-controller deployment has and what
    // `Request::create()` does not produce.
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/');

    $render = static function (array $server) use ($settings): string {
        $request = new Request([], [], [], [], [], $server + [
            'REQUEST_METHOD' => 'GET',
            'SERVER_NAME' => 'app.example.com',
            'HTTP_HOST' => 'app.example.com',
            'SERVER_PORT' => '80',
        ]);

        return ErrorPage::render(
            ErrorReport::of(new RuntimeException('boom'), $request, $settings, dirname(__DIR__, 4), 500, 'Internal Server Error', '2026-01-01T00:00:00+00:00'),
            $settings,
        );
    };

    $served = [
        'SCRIPT_NAME' => '/app/index.php',
        'SCRIPT_FILENAME' => '/var/www/app/index.php',
        'PHP_SELF' => '/app/index.php',
    ];

    expect($render(['REQUEST_URI' => '/app/index.php/orders/42'] + $served))
        ->toContain('<a class="act primary" href="/app/index.php/orders/42">Try again</a>')
        // The FACT GRID is unchanged and still states the path alone: it says which resource was asked
        // for, and the front controller is not part of that. Only the href needs the prefix.
        ->toContain('<dd>GET /orders/42</dd>')
        // The query string rides on the end of the whole address, not between its halves.
        ->and($render(['REQUEST_URI' => '/app/index.php/search?q=foo', 'QUERY_STRING' => 'q=foo'] + $served))
        ->toContain('<a class="act primary" href="/app/index.php/search?q=foo">Try again</a>')
        // AND THE GUARD IS ASKED ABOUT THE WHOLE HREF, NOT ITS TAIL. `getBaseUrl()` is raw — a prefix of
        // REQUEST_URI matched against SCRIPT_NAME, not a value this package composed — so a check on the
        // path alone would have been a question about a string the page does not print. Here the PATH is
        // innocent and the base is the authority wearing a path's clothes; the offer is withheld whole.
        ->and($render([
            'REQUEST_URI' => '/\evil.example/index.php/orders/42',
            'SCRIPT_NAME' => '/\evil.example/index.php',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'PHP_SELF' => '/\evil.example/index.php',
        ]))
        ->not->toContain('Try again')
        ->not->toContain('href="/\\')
        ->toContain('<a class="act primary" href="/">Go home</a>');
});

it('builds the page\'s 405 sentence and the document\'s from one place, and pins where they differ', function () use ($page) {
    // THE DIVERGENCE IS DECLARED, NOT DISCOVERED. lede() opens by saying the page and the problem document
    // agree, and for the 405 they do not say the same words: the page names the verb that was REFUSED and
    // the document cannot, because toFireflyException() is handed a throwable and no request. That is a
    // real constraint rather than an oversight, so it is pinned here the way the 5xx lede is pinned
    // against ProblemMapper::OPAQUE_WITH_REFERENCE above — both sentences read off the same exception, so
    // the day either is reworded this case names the other.
    //
    // WHAT THEY DO SHARE IS THE BUILDER. The verb list, the HEAD filtering and the prose that joins three
    // verbs into "PUT, PATCH or DELETE" live in ProblemMapper::methodSentence() and nowhere else; the two
    // call sites differ by one argument. Two copies of that joining is exactly how a page and a document
    // come to disagree about a failure whose whole content is a list of words.
    $settings = new ErrorPageSettings(trace: false, hints: false);
    $e = new MethodNotAllowedHttpException(['POST', 'HEAD'], 'The GET method is not supported for route orders.');

    $html = $page($e, $settings, 405, 'Method Not Allowed', 'GET', '/orders');
    $document = ProblemMapper::authoredDetail($e);

    if (preg_match('#<p class="message">([^<]+)</p>#', $html, $matched) !== 1) {
        throw new RuntimeException('The production 405 page no longer carries a lede for this test to read.');
    }
    $lede = $matched[1];

    expect($lede)->toBe(ProblemMapper::methodSentence(['POST'], 'GET'))
        ->and($document)->toBe(ProblemMapper::methodSentence(['POST']))
        // The verbs agree — which is the only thing the docblocks and the config reference claim.
        ->and($lede)->toContain('POST')
        ->and($document)->toContain('POST')
        // The sentences do not, and neither surface carries the other's wording.
        ->and($lede)->not->toBe($document)
        ->and($html)->not->toContain($document)
        // The clause the document has no request to write is the whole of the difference.
        ->and($lede)->toContain('GET')
        ->and($document)->not->toContain('GET')
        // HEAD is dropped once, for both, because Symfony adds it beside every GET and no person picks it.
        ->and($lede)->not->toContain('HEAD')
        ->and($document)->not->toContain('HEAD');
});

it('says something a reader does not already know, or falls back to the sentence that does', function () use ($page) {
    // THE MOST ORDINARY FAILURE A LARAVEL APPLICATION PRODUCES is `abort(403)` with no message, and the
    // problem document needs SOME `detail` for it — ProblemMapper::httpMessage() substitutes statusText(),
    // which is the honest answer there, beside a `title` a machine reads. Taken for an authored sentence it
    // printed "Forbidden" as the lede of a page already headed "403 Forbidden": the same word twice, in the
    // one slot reserved for telling a person something new, and the 401 and 403 reassurances below became
    // dead code at the DEFAULT configuration.
    $settings = new ErrorPageSettings(trace: false, hints: false, home: '/', signIn: '/login');

    expect($page(new HttpException(403, ''), $settings, 403, 'Forbidden'))
        ->toContain('<p class="message">You do not have access to that.</p>')
        ->and($page(new HttpException(401, ''), $settings, 401, 'Unauthorized'))
        ->toContain('<p class="message">You need to sign in to see that.</p>')
        ->and($page(new HttpException(429, ''), $settings, 429, 'Too Many Requests'))
        ->toContain('<p class="message">That request could not be completed.</p>')
        // A sentence somebody actually wrote is untouched, which is the whole point of the key.
        ->and($page(new HttpException(403, 'Your trial ended on the 3rd.'), $settings, 403, 'Forbidden'))
        ->toContain('Your trial ended on the 3rd.')
        ->not->toContain('You do not have access to that.');
});

it('answers a wildcard or an absent Accept with a problem document, not Laravel\'s page', function () {
    // `Accept: */*` is what a bare curl sends and what fetch() sends by default, and an absent Accept is
    // what a hand-rolled client sends. Neither NAMES text/html, so neither gets the page — and neither
    // wanted Laravel's stock HTML either, which is what both used to receive for any non-FireflyException.
    $renderer = new ErrorPageRenderer(new ErrorPageSettings(enabled: true));
    $routerMiss = new NotFoundHttpException('The route nope could not be found.');

    $wildcard = Request::create('/orders/9', 'GET', server: ['HTTP_ACCEPT' => '*/*']);
    // Request::create() PUTS a browser's Accept header on a request that was handed none — a harness
    // convenience, and the opposite of what a hand-rolled client sends — so the absent case has to be
    // made absent on purpose. The predicate reads the header bag, which is what is emptied here.
    $absent = Request::create('/orders/9', 'GET');
    $absent->headers->remove('Accept');
    $absent->server->remove('HTTP_ACCEPT');
    $browser = Request::create('/orders/9', 'GET', server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']);

    expect($renderer->rendersProblem($routerMiss, $wildcard))->toBeTrue()
        ->and($renderer->rendersProblem($routerMiss, $absent))->toBeTrue()
        // A browser still gets the page: handles() is asked first, and this predicate agrees with it.
        ->and($renderer->handles($browser))->toBeTrue()
        ->and($renderer->rendersProblem($routerMiss, $browser))->toBeFalse();
});

it('keeps every branch the renderable already had', function () {
    $renderer = new ErrorPageRenderer(new ErrorPageSettings(enabled: true, jsonPaths: ['api/*']));
    $browser = ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml'];
    $fromABrowser = Request::create('/orders/9', 'GET', server: $browser);

    // A FireflyException is a problem document to THIS predicate whoever asked — the first term of the
    // boolean this method replaced, carried over unchanged. The browser still gets the PAGE, because the
    // renderable asks handles() first and never reaches here, and that is asserted on the line below
    // rather than left to a comment. Answering false here instead would be a second, silent change of
    // behaviour: with `firefly.web.error-page.enabled => false` a browser hitting a FireflyException would
    // stop receiving problem+json and start receiving Laravel's stock page, which no key here asked for.
    expect($renderer->handles($fromABrowser))->toBeTrue()
        ->and($renderer->rendersProblem(new ResourceNotFoundException('x', 'X'), $fromABrowser))->toBeTrue()
        ->and($renderer->rendersProblem(new ResourceNotFoundException('x', 'X'), Request::create('/orders/9', 'GET', server: ['HTTP_ACCEPT' => 'application/json'])))->toBeTrue()
        // json-paths still overrides the header in both directions.
        ->and($renderer->rendersProblem(new NotFoundHttpException, Request::create('/api/nope', 'GET', server: $browser)))->toBeTrue()
        // An XMLHttpRequest that names text/html is JavaScript about to read a body.
        ->and($renderer->rendersProblem(new NotFoundHttpException, Request::create('/orders/9', 'GET', server: [...$browser, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])))->toBeTrue();
});

it('leaves a caller that expressed no preference to Laravel when the fallback is switched off', function () {
    // The escape hatch for an application that has its own opinion about an unrouted URL: with the key off,
    // a caller who named nothing falls through exactly as it did before, while a FireflyException, a JSON
    // client and an api/* path are all unaffected.
    $off = new ErrorPageRenderer(new ErrorPageSettings(enabled: true, jsonPaths: ['api/*'], problemFallback: false));
    $wildcard = Request::create('/orders/9', 'GET', server: ['HTTP_ACCEPT' => '*/*']);

    expect($off->rendersProblem(new NotFoundHttpException, $wildcard))->toBeFalse()
        ->and($off->rendersProblem(new ResourceNotFoundException('x', 'X'), $wildcard))->toBeTrue()
        ->and($off->rendersProblem(new NotFoundHttpException, Request::create('/api/nope', 'GET')))->toBeTrue();
});

it('claims nothing at all when the page is switched off and the caller is a browser', function () {
    // `enabled: false` means "use Laravel's stock error page", and it must keep meaning that: a browser
    // gets Laravel's page, and the fallback does not quietly turn it into JSON.
    $off = new ErrorPageRenderer(new ErrorPageSettings(enabled: false));
    $browser = Request::create('/orders/9', 'GET', server: ['HTTP_ACCEPT' => 'text/html']);

    expect($off->handles($browser))->toBeFalse()
        ->and($off->rendersProblem(new NotFoundHttpException, $browser))->toBeFalse();
});
