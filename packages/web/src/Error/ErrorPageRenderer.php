<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Kernel\Exception\FireflyException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
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
        private readonly ?ViewFactory $views = null,
    ) {}

    /**
     * Whether this package may answer for this throwable AT ALL — asked before either negotiation below,
     * because both of them look at the REQUEST and neither looks at what was thrown.
     *
     * THREE THROWABLES BELONG TO LARAVEL'S OWN HANDLER, AND CLAIMING THEM DESTROYS THE FAILURE.
     * Handler::render() consults renderViaCallbacks() — where this package's renderable lives — BEFORE its
     * own `match (true)`, whose arms resolve `HttpResponseException` (which literally CARRIES the response
     * to return), `AuthenticationException` (401, or the guest redirect to the login page) and
     * `ValidationException` (422 with the field errors, or a redirect back with them in the session). Not
     * one of the three is a FireflyException, and not one implements HttpExceptionInterface, so
     * ProblemMapper::toFireflyException() drops every one of them to its default arm and answers
     * 500 / `INTERNAL_ERROR` / "An unexpected error occurred." — an opaque internal error in place of a
     * precisely described one, reported as a 500 into the bargain. A form POST that failed validation came
     * back as a 500 with no `errors` member in it; a 401 came back as a 500.
     *
     * That is exactly the silent failure this whole surface exists to remove, so the rule is the plain one:
     * a throwable Laravel resolves for itself is not this package's to describe, and the renderable returns
     * null for it — for the PAGE as well as for the problem document, because `handles()` reads the Accept
     * header alone and would otherwise draw a diagnostic 500 page over a browser's redirect-back-with-errors.
     *
     * THE LIST IS NAMED, AND THE TRIPWIRE UNDER IT IS NOT THIS PREDICATE'S OWN TEST. ErrorPageTest asserts
     * this method against each of the three, which pins what THIS method does and would go on passing for
     * ever if Laravel grew a fourth arm: the predicate knows nothing about the handler, so a test that
     * constructs the three exceptions itself cannot notice a fourth. That test is therefore not the
     * protection, and saying it was would have told a maintainer on a Laravel upgrade that the list
     * re-checks itself. LaravelHandlerArmsTest is the protection: it reads `Handler::render()` out of the
     * INSTALLED Laravel through reflection, extracts the classes its `match (true)` resolves, and fails
     * unless that set is exactly these three — so a release that adds a fourth arm is a failing test naming
     * the class to add here, and not a silent 500 in production.
     */
    public function describes(Throwable $e): bool
    {
        return ! $e instanceof ValidationException
            && ! $e instanceof AuthenticationException
            && ! $e instanceof HttpResponseException;
    }

    /** Whether this request should be answered with the HTML page rather than with problem+json. */
    public function handles(Request $request): bool
    {
        return $this->settings->enabled && $this->prefersHtml($request);
    }

    /**
     * Whether the caller is a PERSON AT A BROWSER — the negotiation above, and nothing about this page.
     *
     * TWO QUESTIONS, NOT ONE. `handles()` answers "should the framework's page be rendered for this
     * request", and that is rightly false when `firefly.web.error-page.enabled` is off. But "who is asking"
     * is a question other features need answered independently of how a failure is drawn: the security
     * entry point decides whether to send an anonymous request to the LOGIN PAGE, and a browser does not
     * stop being a browser because the application chose Laravel's stock error page over this one. For one
     * release the entry point asked `handles()`, and a documented, legitimate branding choice silently
     * turned every form-login redirect into a 401 — nothing in the security docs mentioned the error page
     * at all, because there was never meant to be a coupling. This method is the browser test on its own,
     * with the feature flag left to `handles()`.
     *
     * `json-paths` DOES take part, flag or no flag, because it says what the URL IS rather than what the
     * page does: an `api/*` URL is a machine surface for a login redirect exactly as it is for a 404, and a
     * copied browser header must not turn an API call into a `302 /login`.
     */
    public function prefersHtml(Request $request): bool
    {
        if ($request->ajax() || $request->wantsJson()) {
            return false;
        }

        // A path the application declares as an API answers with a problem document whatever the caller
        // asked for. This is checked BEFORE the Accept header, not after, because it is the stronger
        // statement: the header says who is asking, the path says what the URL IS.
        if ($this->settings->isJsonPath($request->path())) {
            return false;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }

    /**
     * Whether this path must answer as JSON even though nothing about the REQUEST asked for it.
     *
     * Declining the HTML page is only half of what `json-paths` has to do. A URL under `api/*` that matches
     * no route at all throws a Symfony HttpException, which is not a FireflyException, and a browser's
     * Accept header means `expectsJson()` is false — so both of the problem+json branches missed it and the
     * request fell through to Laravel's own error page. An API path that answers with a framework's stock
     * HTML is exactly the outcome this setting exists to prevent, so the caller asks this too.
     */
    public function forcesJson(Request $request): bool
    {
        return $this->settings->enabled && $this->settings->isJsonPath($request->path());
    }

    /**
     * Whether this failure is answered with a problem document — the WHOLE of that decision, asked after
     * `handles()` has already answered "is this a page".
     *
     * THE BUG THIS FIXES. The rule used to live in WebServiceProvider as
     * `$e instanceof FireflyException || $request->expectsJson() || $page->forcesJson($request)`, and its
     * gap was the commonest client there is. A WILDCARD Accept header is what a bare `curl` sends and what
     * `fetch()` sends by default; an absent Accept is what a hand-rolled client sends. Neither NAMES
     * text/html, so neither got the page — and `expectsJson()` is false for both (`wantsJson()` tests the
     * FIRST acceptable type, and a wildcard is not a JSON type; `ajax()` is false) — so a router 404 on a
     * path outside `json-paths` fell all the way through to Laravel's stock HTML page. A client that asked
     * for anything was handed markup, while the documentation had promised it a problem document since the
     * page shipped.
     *
     * THE FALLBACK IS THE LAST TERM, NOT THE FIRST. A FireflyException, a JSON client and a `json-paths` URL
     * are answered exactly as they were; this term only adds an answer where the package previously gave
     * none.
     *
     * AND IT CLAIMS EVERY CALLER THAT IS NEITHER A BROWSER NOR A JSON CLIENT — not only the one that named
     * nothing at all. `! prefersHtml()` is true of a wildcard Accept and of an absent one, the two cases
     * this term was written for, and it is equally true of `Accept: application/xml`, `text/plain` or
     * `image/png`: a caller that named a concrete type this package does not render an error in. That is
     * deliberate, and it is the narrower rule that would be the inconsistency. A FireflyException has
     * ALWAYS been answered with problem+json whatever the Accept header said — the first term above,
     * unchanged since before this method existed — so a fallback restricted to a literal wildcard would
     * hand one XML client a problem document for a taxonomy 404 and Laravel's stock HTML page for a router
     * 404, which is one application answering one failure two unrelated-looking ways and is the exact shape
     * the class comment above opens by describing. Nor is there a third shape to offer: the
     * MessageConverterRegistry converts what a CONTROLLER returns and an application may well add XML to
     * it, but neither error renderer is wired through it, and problem+json is the only machine-readable
     * document this package writes. So the key is `problem-fallback` rather than a wildcard-shaped name,
     * and every sentence that documents it says what it actually claims: a caller that did not name
     * text/html and did not ask for JSON.
     *
     * IT IS ALSO GATED ON THE FLAG, FOR `forcesJson()`'S REASON. The fallback asks `prefersHtml()`, and
     * `prefersHtml()` folds `json-paths` in — so without `enabled` on the term, an `api/*` URL hit by a
     * BROWSER would be claimed here with the page switched off, which is precisely the answer the same flag
     * on `forcesJson()` was written to withhold (`forces nothing at all when the page is switched off`).
     * One key would have meant two things: "do not draw the page" for one branch and "draw nothing at all"
     * for the branch beside it. `firefly.web.error-page.enabled => false` keeps its documented meaning —
     * this package stops adding answers and Laravel's own handler is left to it — while a FireflyException
     * and a JSON client, which were never gated on the flag, are still answered exactly as before.
     *
     * AND A THROWABLE LARAVEL RESOLVES ITSELF NEVER REACHES HERE: see describes(), which the renderable
     * asks first, and which is the only part of this negotiation that looks at what was thrown.
     */
    public function rendersProblem(Throwable $e, Request $request): bool
    {
        if ($e instanceof FireflyException || $request->expectsJson() || $this->forcesJson($request)) {
            return true;
        }

        return $this->settings->enabled && $this->settings->problemFallback && ! $this->prefersHtml($request);
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
            $this->body($report, $status),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * The application's own view for this status when it declared one, and the framework's page otherwise.
     *
     * THE FALLBACK IS NOT POLITENESS, IT IS THE POINT. This runs while the application is already failing,
     * and an override is application code — a view that references a missing variable, a layout that was
     * renamed, a component that queries a database which is the very thing that is down. Letting that throw
     * would replace a diagnostic page with a white screen at exactly the moment someone needs to read one,
     * so a failing override falls back to the built-in page rather than propagating. The override gets the
     * same ErrorReport the built-in page does, so it can show as much or as little as it likes and is
     * subject to the same `trace` gate — a custom view cannot print a stack trace the settings withheld,
     * because the report it was handed never gathered one.
     */
    private function body(ErrorReport $report, int $status): string
    {
        $view = $this->settings->viewFor($status);

        if ($view !== null && $this->views !== null) {
            try {
                if ($this->views->exists($view)) {
                    return $this->views->make($view, ['error' => $report, 'settings' => $this->settings])->render();
                }
            } catch (Throwable) {
                // Fall through to the built-in page.
            }
        }

        return ErrorPage::render($report, $this->settings);
    }
}
