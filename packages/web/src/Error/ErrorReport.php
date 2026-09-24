<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Http\Request;
use Throwable;

/**
 * Everything the HTML error page shows, assembled once from the exception, the request and the settings.
 *
 * SEPARATE FROM THE PAGE ON PURPOSE. Deciding what may be disclosed and building markup are different jobs
 * with different failure modes, and keeping them apart means the disclosure rule is stated once, in one
 * place, and is testable without parsing HTML. It also makes the rule enforceable rather than decorative:
 * when `trace` is off, this class never reads a source file, never walks the trace and never copies the
 * exception message — so a template mistake cannot leak what was never gathered.
 *
 * THE FIELDS MATCH THE PROBLEM DOCUMENT. `code`, `category` and `severity` come from the same
 * ErrorResponse::fromException() the JSON renderer uses, so the page a browser sees and the payload a client
 * sees describe the same error with the same vocabulary. A support ticket quoting the code off the page
 * finds the same code in the log.
 *
 * PATHS ARE SHORTENED BY SourcePaths, NOT HERE. Three lines of str_starts_with used to live in this class,
 * and they were the reason a 500 page measured 10,108 pixels: they miss whenever a deployment names one
 * directory two ways, and then not one of a hundred rows is shortened. The rule is a collaborator now
 * because it is worth unit-testing on its own — a symlinked base path is a case no rendered trace can be
 * asked to produce.
 */
final readonly class ErrorReport
{
    /**
     * `reference` — the id a person quotes (the same value problem+json publishes as `traceId`): the
     * request's W3C trace id when tracing gave it one, and its correlation id otherwise; '' only when no
     * request is known. It is the one field the PRODUCTION page adds beyond the status and the code: it
     * names nothing internal, and it is the single action a reader of that page can take — report the id —
     * which the JSON document already invites and the HTML page did not make possible.
     *
     * `correlationId` is carried BESIDE it, never in place of it, and is what problem+json publishes under
     * that name. The page shows it as its own row only when it differs from the reference, because two
     * rows holding one value teach a reader that the ids are interchangeable, which is the confusion the
     * two members exist to prevent.
     *
     * `path` AND `query` ARE TWO FIELDS BECAUSE THEY ARE TWO PROMISES. `path` is root-relative and built
     * as '/'.ltrim($request->path(), '/'), which is what makes it safe to put in an href: it begins with
     * exactly one slash, so it cannot carry a scheme and cannot become protocol-relative. `query` is what
     * Laravel's `path()` THROWS AWAY — it answers `search` for /search?q=foo&page=2 — and the page's "Try
     * again" link is the one place that loss is not cosmetic: a 5xx reader who is offered their search
     * back without their search terms has been handed a different request than the one that failed. It is
     * Symfony's `getQueryString()`, which is normalised (pairs sorted, empty query answered as null, taken
     * here as '') and percent-encoded to RFC 3986, so `<`, `>` and `"` are already `%3C`, `%3E` and `%22`
     * before htmlspecialchars ever sees them and no spelling of it can end the attribute it sits in. The
     * fact grid still shows `path` alone: the grid is a statement about the request, and a query string is
     * where a session token or a search a person would rather not screenshot tends to live.
     *
     * `baseUrl` IS THE THIRD PIECE OF THE SAME ADDRESS, and it exists because `path` is base-URL-STRIPPED.
     * Laravel's `path()` is Symfony's `getPathInfo()`, which answers `orders/42` for a request to
     * /app/index.php/orders/42 — the front controller's own prefix is deliberately not in it, because a
     * route is matched on the path info and nothing else. The "Try again" link is the one place that
     * absence is not cosmetic: on a deployment served under a base path, a href built from `path` alone
     * names a URL the deployment never serves, so the primary action on every 5xx page points off the
     * application. It is Symfony's `getBaseUrl()` — the same value `LoginPageAction` and the OAuth2 link
     * builders prepend for exactly this reason — and it is '' for the ordinary rewrite-to-the-root
     * deployment, where the concatenation is `path` unchanged. The grid is untouched by it for the same
     * reason it omits the query: it states which resource was asked for, and the front controller is no
     * more part of that than a search term is. ErrorPage::retry() puts the CONCATENATION through
     * ErrorPageSettings::url(), never the halves, because a guard that checked the tail and trusted the
     * head would have been asking about a string the page does not print.
     *
     * @param  list<ErrorFrame>  $frames
     * @param  list<array{class: string, message: string, location: string}>  $previous
     * @param  list<string>  $allowed
     */
    private function __construct(
        public int $status,
        public string $reason,
        public string $code,
        public string $category,
        public string $severity,
        public string $method,
        public string $path,
        public string $query,
        public string $timestamp,
        public bool $detailed,
        // The front controller's own prefix, '' when there is none — see the `path`/`query` note above.
        // It carries a default so that a report assembled by hand (a renderer test, an Octane-shaped
        // fixture) is not obliged to know about a deployment shape it is not exercising.
        public string $baseUrl = '',
        public string $exceptionClass = '',
        public string $message = '',
        public string $location = '',
        public array $frames = [],
        public array $previous = [],
        public string $reference = '',
        public string $correlationId = '',
        /** @var list<string> */
        public array $allowed = [],
        public string $publicDetail = '',
        public int $frameCount = 0,
        public int $appFrameCount = 0,
    ) {}

    public static function of(Throwable $e, Request $request, ErrorPageSettings $settings, string $basePath, int $status, string $reason, string $timestamp): self
    {
        $payload = ErrorResponse::fromException(ProblemMapper::toFireflyException($e), instance: ProblemMapper::instanceFor($request), timestamp: $timestamp)->toArray();

        // The reference is the id a person can act on: the W3C trace id when this request has one, the
        // correlation id otherwise. The correlation id is carried beside it, never replaced by it.
        $reference = TraceContext::referenceFor($request);
        $correlationId = CorrelationIdFilter::of($request);

        // The verbs a 405 permits are already parsed, HEAD-filtered and published as an extension member;
        // the page threw them away and shrugged instead. Read with an explicit is_array + foreach +
        // is_string loop rather than array_filter, which cannot give PHPStan at level max a list<string>.
        $allowed = [];
        if (is_array($payload['allowed'] ?? null)) {
            foreach ($payload['allowed'] as $method) {
                if (is_string($method)) {
                    $allowed[] = $method;
                }
            }
        }

        $publicDetail = $settings->authoredDetail ? ProblemMapper::authoredDetail($e) : '';

        $public = new self(
            status: $status,
            reason: $reason,
            code: is_string($payload['code'] ?? null) ? $payload['code'] : 'INTERNAL_ERROR',
            category: is_string($payload['category'] ?? null) ? $payload['category'] : '',
            severity: is_string($payload['severity'] ?? null) ? $payload['severity'] : '',
            method: $request->getMethod(),
            path: '/'.ltrim($request->path(), '/'),
            query: $request->getQueryString() ?? '',
            timestamp: $timestamp,
            detailed: false,
            baseUrl: $request->getBaseUrl(),
            reference: $reference,
            correlationId: $correlationId,
            allowed: $allowed,
            publicDetail: $publicDetail,
        );

        if (! $settings->trace) {
            return $public;
        }

        $roots = SourcePaths::roots($e, $basePath);

        // Built once, counted, then budgeted — three statements rather than one expression, because the
        // counts describe the UNTRIMMED stack and the page needs both numbers to say "8 of 104 frames · 10
        // in your code" without lying about either half.
        $frames = self::frames($e, $roots, $settings->excerptLines);
        $appFrames = 0;
        foreach ($frames as $frame) {
            if (! $frame->vendor) {
                $appFrames++;
            }
        }

        return new self(
            status: $public->status,
            reason: $public->reason,
            code: $public->code,
            category: $public->category,
            severity: $public->severity,
            method: $public->method,
            path: $public->path,
            query: $public->query,
            timestamp: $public->timestamp,
            detailed: true,
            baseUrl: $public->baseUrl,
            exceptionClass: $e::class,
            message: $e->getMessage(),
            location: SourcePaths::shorten($e->getFile(), $roots).':'.$e->getLine(),
            frames: self::budget($frames, $settings->maxFrames),
            previous: self::previous($e, $roots),
            reference: $reference,
            correlationId: $correlationId,
            allowed: $allowed,
            publicDetail: $publicDetail,
            frameCount: count($frames),
            appFrameCount: $appFrames,
        );
    }

    /**
     * The frames the page will actually build, in stack order.
     *
     * A HARD TRIM, NOT A STYLE. The alternative — render every frame and hide the tail with CSS — keeps a
     * hundred frames in the DOM that a screen reader still walks and a find-in-page still matches, and
     * costs the same hundred escapes on a page that renders while the application is already failing.
     *
     * YOUR FRAMES ARE NEVER WHAT GETS TRIMMED. Taking the first N would drop an application frame sixty
     * deep — a controller called from a queue worker, a listener under the event dispatcher — which is
     * precisely the frame a reader opened this page for. So the budget is spent on application frames
     * first and filled with vendor frames in stack order, and the result is still in stack order because
     * both passes walk the same list.
     *
     * @param  list<ErrorFrame>  $frames
     * @return list<ErrorFrame>
     */
    private static function budget(array $frames, int $max): array
    {
        if (count($frames) <= $max) {
            return $frames;
        }

        $keep = [];

        foreach ($frames as $i => $frame) {
            if (! $frame->vendor && count($keep) < $max) {
                $keep[$i] = true;
            }
        }

        foreach (array_keys($frames) as $i) {
            if (count($keep) >= $max) {
                break;
            }

            $keep[$i] = true;
        }

        $kept = [];
        foreach ($frames as $i => $frame) {
            if (isset($keep[$i])) {
                $kept[] = $frame;
            }
        }

        return $kept;
    }

    /**
     * The throw site first, then the call stack — which is the order a reader wants and the opposite of the
     * order `getTrace()` returns it in relative to `getFile()`. PHP's trace starts at the CALLER of the
     * throwing frame, so the throwing line itself appears nowhere in it and has to be prepended.
     *
     * @param  list<string>  $roots
     * @return list<ErrorFrame>
     */
    private static function frames(Throwable $e, array $roots, int $excerptLines): array
    {
        $frames = [self::frame($e->getFile(), $e->getLine(), 'throw', $roots, $excerptLines, 0)];

        foreach ($e->getTrace() as $entry) {
            $file = is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $line = is_int($entry['line'] ?? null) ? $entry['line'] : null;

            $class = $entry['class'] ?? '';
            $type = $entry['type'] ?? '';
            $function = $entry['function'];

            $frames[] = self::frame($file, $line, $class.$type.$function.'()', $roots, $excerptLines, count($frames));
        }

        return $frames;
    }

    /**
     * @param  list<string>  $roots
     */
    private static function frame(string $file, ?int $line, string $call, array $roots, int $excerptLines, int $index): ErrorFrame
    {
        $vendor = $file === '' || str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\');

        return new ErrorFrame(
            file: $file,
            shortFile: $file === '' ? '[internal function]' : SourcePaths::shorten($file, $roots),
            line: $line,
            call: $call,
            vendor: $vendor,
            excerpt: $vendor ? [] : self::excerpt($file, $line, $excerptLines),
            index: $index,
        );
    }

    /**
     * The lines around $line, as line number => text.
     *
     * Guarded at every step because this runs while the application is ALREADY failing: the file may have
     * been deleted since the trace was captured, may be unreadable, or may be an eval()'d fragment with no
     * path at all. An error page that throws while explaining a throw is the worst possible outcome, so
     * every branch here answers with an empty excerpt rather than an exception.
     *
     * @return array<int, string>
     */
    private static function excerpt(string $file, ?int $line, int $radius): array
    {
        if ($line === null || $radius === 0 || $file === '' || ! is_file($file) || ! is_readable($file)) {
            return [];
        }

        // A generated proxy or a minified vendor bundle can be one enormous line; reading it whole to show
        // seven lines around a fault is not a trade worth making on a page that renders under duress.
        if ((filesize($file) ?: 0) > 2 * 1024 * 1024) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $from = max(1, $line - intdiv($radius, 2));
        $to = min(count($lines), $from + $radius - 1);

        $excerpt = [];
        for ($n = $from; $n <= $to; $n++) {
            $excerpt[$n] = $lines[$n - 1] ?? '';
        }

        return $excerpt;
    }

    /**
     * The `previous` chain, which is where the real cause usually is: firefly/web wraps a binding failure in
     * an InvalidRequestException, the container wraps a constructor throw, and the message on the outermost
     * exception is the least specific one in the chain.
     *
     * @param  list<string>  $roots
     * @return list<array{class: string, message: string, location: string}>
     */
    private static function previous(Throwable $e, array $roots): array
    {
        $chain = [];
        $seen = 0;

        while (($e = $e->getPrevious()) !== null && $seen < 8) {
            $seen++;
            $chain[] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'location' => SourcePaths::shorten($e->getFile(), $roots).':'.$e->getLine(),
            ];
        }

        return $chain;
    }
}
