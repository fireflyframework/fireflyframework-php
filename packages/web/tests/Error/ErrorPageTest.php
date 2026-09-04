<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Error\ErrorPage;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ErrorReport;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Http\Request;
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
    $renderer = new ProblemDetailsRenderer(new ErrorPageSettings(trace: false));
    $body = (string) $renderer->render(new RuntimeException('internal detail: /srv/app/.env'), Request::create('/api/x'))->getContent();

    expect($body)->not->toContain('/srv/app/.env')
        ->toContain(ProblemMapper::OPAQUE)
        ->toContain('INTERNAL_ERROR');

    // And with the gate open — a developer's machine — the real message comes through.
    $debug = new ProblemDetailsRenderer(new ErrorPageSettings(trace: true));
    expect((string) $debug->render(new RuntimeException('internal detail: /srv/app/.env'), Request::create('/api/x'))->getContent())
        ->toContain('/srv/app/.env');
});

it('defaults to withholding when no settings object was bound at all', function () {
    // A JSON-only deployment may never construct ErrorPageSettings. The default has to be the safe one:
    // an absent gate must not mean an open one.
    expect((string) (new ProblemDetailsRenderer)->render(new RuntimeException('leak me'), Request::create('/api/x'))->getContent())
        ->not->toContain('leak me')
        ->toContain(ProblemMapper::OPAQUE);
});
