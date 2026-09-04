<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Decides whether a failure is answered with a page or with a problem document, and renders the page.
 *
 * THE BUG THIS FIXES. The renderable was `$e instanceof FireflyException || $request->expectsJson()`, so
 * ANY FireflyException rendered as problem+json regardless of who asked — which meant a person clicking a
 * stale link to /orders/999999 in a browser was shown a raw JSON blob. The framework's own exception
 * taxonomy, the thing that makes its errors consistent for clients, was what made them unreadable for
 * people. Meanwhile a URL matching no route at all threw a Symfony HttpException, missed that branch, and
 * fell through to Laravel's stock error page — so one application produced two unrelated-looking 404s
 * depending on which kind of 404 it was.
 *
 * WHY NOT `$request->expectsJson()` FOR THE DECISION. Its negation is not "wants HTML". A bare `curl` sends
 * a WILDCARD Accept header, which `acceptsHtml()` answers true for, so keying off it would have turned every
 * unadorned command-line request against an API into an HTML page — a worse regression than the bug. The
 * rule is therefore explicit: the page is served only when the client NAMED `text/html` (or
 * `application/xhtml+xml`) in its Accept header, which every browser does and no API client does by
 * accident. A wildcard alone is not an opinion, and is answered with the machine-readable form.
 *
 * An XMLHttpRequest is excluded even when it names text/html, because its caller is JavaScript that is going
 * to read a body, not a person who is going to read a page.
 */
final class ErrorPageRenderer
{
    public function __construct(
        private readonly ErrorPageSettings $settings,
        private readonly string $basePath = '',
    ) {}

    /** Whether this request should be answered with the HTML page rather than with problem+json. */
    public function handles(Request $request): bool
    {
        if (! $this->settings->enabled || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }

    public function render(Throwable $e, Request $request): Response
    {
        $exception = ProblemMapper::toFireflyException($e);
        $status = $exception->httpStatus();

        $report = ErrorReport::of(
            $e,
            $request,
            $this->settings,
            $this->basePath,
            $status,
            ProblemMapper::statusText($status),
            (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
        );

        return new Response(
            ErrorPage::render($report, $this->settings),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
