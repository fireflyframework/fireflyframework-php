<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

/**
 * The HTML error page, built as a string.
 *
 * WHY NOT BLADE. This page renders when the application is already failing, and a Blade view is the one
 * thing that cannot be relied on then: the failure may BE a view — a compile error, a missing path, a view
 * factory that never got bound because the container is half-built — and rendering a second view to explain
 * the first produces a white screen and no clue. So the page has no dependencies at all: no container
 * lookups, no view factory, no filesystem read of its own, and no CSS or font from a network the box may not
 * have. String concatenation is not the elegant choice; it is the one that still works when nothing else
 * does.
 *
 * THE DESIGN IS THE FRAMEWORK'S, not a fourth one. The palette, the type stack and the radii are the admin
 * dashboard's — this is a diagnostic surface and it should read as one — while the composition is the
 * welcome page's: centred, generous, a single column. A person meets this page in the same session in which
 * they meet those two, and a third visual language would just be noise.
 *
 * THE SIGNATURE IS THE TRACE, because that is what the page is FOR. A raw PHP trace is a hundred frames of
 * which ten are yours; here the application's frames are the list — accented, one line each, the first one
 * with its source already open — and every dependency frame sits behind a single disclosure below them,
 * closed while there is a list above it to read and open when there is not. That split is the entire
 * difference between scrolling a trace and reading one, and it is drawn with
 * `<details>` and CSS — no JavaScript, so it works with scripts disabled and in whatever a container's
 * minimal browser turns out to be.
 */
final class ErrorPage
{
    public static function render(ErrorReport $report, ErrorPageSettings $settings): string
    {
        $title = self::e($report->status.' '.$report->reason.' · '.$settings->title);

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$title.'</title>'
            .self::favicon()
            .'<style>'.self::css().'</style></head><body>'
            .'<main class="sheet">'
            .self::header($report, $settings)
            .self::facts($report, $settings)
            .self::detail($report)
            .self::footer($report, $settings)
            .'</main>'.self::clipboard($settings, $report->reference !== '').'</body></html>';
    }

    private static function header(ErrorReport $report, ErrorPageSettings $settings): string
    {
        $tone = match (true) {
            $report->status >= 500 => 'down',
            $report->status >= 400 => 'warn',
            default => 'idle',
        };

        $html = '<header class="head">'
            .'<span class="mark"><span class="dot"></span>'.self::e($settings->title).'</span>'
            .'<p class="status '.$tone.'"><b>'.self::e((string) $report->status).'</b><span>'.self::e($report->reason).'</span></p>';

        // The stable error code is the one thing worth carrying off this page — into a support ticket, into
        // a log search — so it is the largest thing under the status rather than a detail in a table.
        $html .= '<p class="code">'.self::e($report->code).'</p>';

        if ($report->detailed && $report->message !== '') {
            $html .= '<p class="message">'.self::e($report->message).'</p>';
        } elseif (! $report->detailed) {
            // Not `muted`: the lede is the page's sentence, not an aside beside it. It is the one line a
            // production reader is meant to read, and it is now the SAME line the problem document publishes
            // for the same failure, so dimming it was the page disagreeing with itself about its own subject.
            $html .= '<p class="message">'.self::e(self::lede($report, $settings)).'</p>';
        }

        return $html.self::actions($report, $settings).'</header>';
    }

    /**
     * The one sentence a production page says, chosen so that the page and the problem document agree —
     * with one declared exception, which is the 405 and is the last paragraph here.
     *
     * THREE SOURCES, MOST SPECIFIC FIRST. A 405 the ROUTER raised knows something no exception message does
     * — which verb the caller used — so it gets a sentence built from both. Then the AUTHORED sentence:
     * "Order 42 does not exist.", "No such tenant.", the product's replacement for the router's 404. That is
     * what problem+json has always published for the same failure, and a page that said "That page does not
     * exist." instead made one error read two ways. Only when neither applies does the page fall back to its
     * own reassurance, which is all a 5xx can honestly offer — and all a BARE `abort(403)` can, for which
     * "authored" is a word the document uses about a sentence nobody wrote. See authoredSentence().
     *
     * THE 405 BRANCH SITS ABOVE THE `authored-detail` GATE, AND IT IS NOT GOVERNED BY IT — which is worth
     * stating because the ORDER is what decides it. That key exists to say whether the sentence an
     * APPLICATION wrote may reach a person. Nothing of the application's is in this one: the verbs come off
     * the `Allow` header the ROUTER put on its own exception, and the words are written by
     * ProblemMapper::methodSentence(), which is the framework's own prose for this page and for the
     * document alike. So there is nothing for the key to withhold, and turning it off to get the
     * status-and-code page leaves this sentence exactly where it was — the page saying about a 405 what the
     * document beside it already says in its `allowed` member. ErrorPageSettings and
     * skeleton/config/firefly.php state that where an operator reads about the key, and ErrorPageTest pins
     * it, because an undocumented exception to a documented key is the same bug as a wrong default.
     *
     * AND IT IS THE ONE PLACE THE TWO SURFACES DO NOT SAY THE SAME WORDS, which is stated here rather than
     * left for a reader to discover, because the headline above would otherwise be read literally. The page
     * says "That address does not accept a GET request. It accepts POST." where the document says "This
     * address only accepts POST.", and the difference is a clause the document cannot write: it is built
     * from the throwable alone and has no request to read the refused verb off. What the two DO share is
     * the verb list and the prose that joins it, because both sentences come out of one builder —
     * ProblemMapper::methodSentence(), which this branch calls with the request's method and
     * methodNotAllowed() calls without one. ErrorPageTest pins the pair against each other, the same way it
     * pins the 5xx lede against ProblemMapper::OPAQUE_WITH_REFERENCE, so neither wording can be re-decided
     * on its own.
     */
    private static function lede(ErrorReport $report, ErrorPageSettings $settings): string
    {
        if ($report->status === 405 && $report->allowed !== []) {
            return ProblemMapper::methodSentence($report->allowed, $report->method);
        }

        $authored = $settings->authoredDetail ? self::authoredSentence($report) : '';

        if ($authored !== '') {
            return $authored;
        }

        return self::reassurance($report->status, $report->reference);
    }

    /**
     * The sentence somebody WROTE for this failure, or '' when all that is on offer is the reason phrase.
     *
     * A BARE `abort(403)` IS NOT AN AUTHORED SENTENCE, and taking it for one is how the most ordinary
     * failure a Laravel application produces ended up with the worst lede on this page.
     * ProblemMapper::httpMessage() substitutes statusText() for an empty message — the document needs SOME
     * `detail`, and "Forbidden" is the honest one there, beside a `title` a machine reads — so
     * authoredDetail() answers a non-empty string for every abort() that named no sentence. Printed as the
     * lede that read "403 Forbidden" over "Forbidden": the same word twice, the second time in the one slot
     * on the page reserved for telling a person something they did not already know. Worse, it made the
     * reassurances for 401 and 403 — "You need to sign in to see that.", "You do not have access to that."
     * — DEAD CODE at the default configuration, which is the only configuration most deployments run.
     *
     * So the test is not "is `publicDetail` non-empty" but "does it say anything the page does not already
     * say": a value equal to the reason phrase beside the status code, or to the status text for the status,
     * is treated as nothing authored and the page falls through to its own sentence. Both spellings are
     * checked because they are two different sources — `reason` is what the renderer was handed, `statusText`
     * is what ProblemMapper substituted — and they agree only by convention. Nothing an application actually
     * wrote is affected: NOTHING_HERE, "No such tenant." and "Order 42 does not exist." are none of them a
     * reason phrase, and an `abort(403, 'Forbidden')` that deliberately spells the word gets the sentence
     * that explains it instead, which is the better page either way.
     */
    private static function authoredSentence(ErrorReport $report): string
    {
        if ($report->publicDetail === '' || $report->publicDetail === $report->reason) {
            return '';
        }

        return $report->publicDetail === ProblemMapper::statusText($report->status) ? '' : $report->publicDetail;
    }

    /**
     * What a reader can do next — and nothing this deployment did not configure.
     *
     * Every one of the four production screenshots ends at a fact grid: no link home, no way to sign in
     * after a 401, no way to ask again after a 500. The offers are per STATUS AND PER VERB, because a wrong
     * offer is worse than none: "Sign in" on a 404 tells a reader they were refused when they were not, and
     * "Try again" on a failed POST offers to repeat a request a link is incapable of repeating.
     *
     * EVERY href ON THIS PAGE HAS PASSED ErrorPageSettings::url() — the configured values when the settings
     * object was built, and the request's own address in retry() — so there is exactly one vocabulary for
     * what may appear here and exactly one place that knows it.
     */
    private static function actions(ErrorReport $report, ErrorPageSettings $settings): string
    {
        if (! $settings->actions) {
            return '';
        }

        /** @var list<array{href: string, label: string}> $links */
        $links = [];

        if ($report->status === 401 && $settings->signIn !== '') {
            $links[] = ['href' => $settings->signIn, 'label' => 'Sign in'];
        }

        // A 5xx is the one failure whose reader can act without leaving the page they wanted: ask for it
        // again. A 4xx cannot be retried into success — the address, the verb or the permission is wrong.
        //
        // AND ONLY IF THE REQUEST WAS A GET OR A HEAD, because A LINK CANNOT RE-ISSUE A BODY. An `<a href>`
        // is a GET, whatever the request it claims to repeat: on a POST-only route it lands the reader on
        // this wave's OWN 405 page ("That address does not accept a GET request. It accepts POST."), and on
        // a route that answers both verbs it silently sends a DIFFERENT request — same address, no form
        // fields, no idempotency — while the label says "again". The query string is carried; the verb and
        // the body are not, and there is no markup that would carry them without a form and a script this
        // page refuses to grow. So the offer is withheld rather than made falsely: a POST that 500s gets
        // "Go home" and "Contact support", which are the two things that are actually true for it.
        if ($report->status >= 500 && in_array($report->method, ['GET', 'HEAD'], true)) {
            $retry = self::retry($report);

            if ($retry !== '') {
                $links[] = ['href' => $retry, 'label' => 'Try again'];
            }
        }

        if ($settings->home !== '') {
            $links[] = ['href' => $settings->home, 'label' => 'Go home'];
        }

        if ($settings->support !== '') {
            $links[] = ['href' => $settings->support, 'label' => 'Contact support'];
        }

        if ($links === []) {
            return '';
        }

        $html = '<nav class="acts" aria-label="What you can do next">';
        foreach ($links as $i => $link) {
            $html .= '<a class="act'.($i === 0 ? ' primary' : '').'" href="'.self::e($link['href']).'">'
                .self::e($link['label']).'</a>';
        }

        return $html.'</nav>';
    }

    /**
     * THE REQUEST THAT FAILED, not merely the path it was addressed to — or '' when this page declines to
     * spell that address at all.
     *
     * "Try again" is the primary action on every 5xx, and in its first spelling it dropped the query string:
     * `$report->path` comes from Laravel's `path()`, which answers `search` for /search?q=foo&page=2, so a
     * reader whose SEARCH had failed was handed a link to an empty one and had to retype what they had
     * already typed. The word "again" is a promise about the request, and a link that re-issues a different
     * one breaks it silently — the page looks right, and only the reader knows what was lost.
     *
     * THE ADDRESS GOES THROUGH THE SAME GUARD AS EVERY OTHER HREF ON THIS PAGE, and the first spelling of
     * this method did not — it trusted `$report->path` on the strength of a claim that turns out to be
     * false. That claim was: the path is '/'-prefixed and ltrim()ed, so the href begins with exactly one
     * slash, so it can carry neither a scheme nor a protocol-relative `//host`. The first two clauses hold
     * and the conclusion does not, because `//host` is not the only spelling of an authority. Symfony
     * refuses a backslash in a request target ONLY inside `Request::create()`; `prepareRequestUri()` — the
     * path every real request takes — neither refuses nor normalises one, so a REQUEST_URI of
     * `/\evil.example` reaches `path()` as `\evil.example` and this method as `/\evil.example`. For a
     * special scheme the URL parser's relative-slash state treats `\` exactly like `/`, so a browser reads
     * that as `https://evil.example/` — the PRIMARY action on the page, pointing off the origin, during the
     * incident that is exactly when a 5xx lands on an arbitrary path and a reader clicks "Try again".
     *
     * ErrorPageSettings::url() has refused that spelling for every operator-supplied href since the action
     * row existed, along with the tab, LF and CR a parser DELETES wherever they sit. This method asks it the
     * same question and accepts the address only when it comes back UNCHANGED — not merely non-empty, since
     * a guard that trimmed an edge would hand back a different request than the one that failed, and this
     * link's whole promise is that it is the same one. Anything else, and actions() makes no offer: a link
     * the page cannot spell truthfully is worse than a row with one fewer button on it.
     *
     * AND THE FRONT CONTROLLER'S OWN PREFIX IS PART OF THE ADDRESS, which the second spelling of this
     * method also dropped. `$report->path` is Laravel's `path()`, which is Symfony's `getPathInfo()` and is
     * base-URL-STRIPPED by design: a request for /app/index.php/orders/42 answers `orders/42`, because the
     * router matches on the path info and the front controller is not part of what was asked for. Prefixed
     * with a slash that is `/orders/42` — a URL the deployment does not serve, so on every box served under
     * a base path the PRIMARY action on every 5xx page 404s or leaves the application entirely. The family
     * has one settled spelling for this and it is `$request->getBaseUrl().$path`, which LoginPageAction,
     * AuthorizationEndpoint, OAuth2LoginPageLinks and FakeAuthorizationServer all use and which
     * `it keeps the base path a front controller is served under` pins in firefly/security. The value rides
     * on the report as `ErrorReport::$baseUrl` and is '' for the ordinary rewrite-to-the-root deployment,
     * where the concatenation is the path unchanged.
     *
     * THE GUARD SEES THE WHOLE HREF, not its tail. `getBaseUrl()` is raw — it is a prefix of REQUEST_URI
     * matched against SCRIPT_NAME, not a value this package composed — so checking `$report->path` and then
     * concatenating something in FRONT of it would be asking the question about a string that is not the
     * one printed. The concatenation is built first and `ErrorPageSettings::url()` is asked about that, so
     * the `/\evil.example` refusal above holds for the href a browser will actually read.
     *
     * THE QUERY IS SAFE ON ITS OWN TERMS. It is Symfony's `getQueryString()`, which percent-encodes to
     * RFC 3986 — `"` is already `%22`
     * and `<` is `%3C` before this page escapes anything — so it cannot end the attribute, cannot introduce
     * a second `?`, and passes through htmlspecialchars byte for byte except for the `&` between pairs,
     * which becomes `&amp;` because that is how an ampersand is spelled inside an HTML attribute value.
     * Symfony also sorts the pairs, so the link may read `?page=2&q=foo` where the reader typed
     * `?q=foo&page=2`; it is the same request, and normalising is the price of a spelling this page can
     * make promises about.
     */
    private static function retry(ErrorReport $report): string
    {
        $target = $report->baseUrl.$report->path;

        if (ErrorPageSettings::url($target) !== $target) {
            return '';
        }

        return $report->query === '' ? $target : $target.'?'.$report->query;
    }

    /**
     * What is true about this request, as a card grid — with the reference as its own composed cell.
     *
     * THE GRID DRAWS ITS OWN RULES. It used to be `gap:1px` over a line-coloured container, which is a neat
     * trick until the fact count is not a multiple of the column count — and the column count is
     * `auto-fit`, so it is not knowable here. Six facts in four columns left two DEAD BEIGE CELLS on every
     * production page. Now the container is the panel ground and each cell draws a rule up and to the left
     * with an outset shadow, which the container's `overflow:hidden` clips on the first row and column; a
     * ragged last row is simply panel-coloured, like the panel it is in.
     */
    private static function facts(ErrorReport $report, ErrorPageSettings $settings): string
    {
        $rows = [
            'Request' => $report->method.' '.$report->path,
            'Code' => $report->code,
            'Category' => $report->category,
            'Severity' => $report->severity,
            'When' => $report->timestamp,
        ];

        if ($report->detailed) {
            $rows['Exception'] = $report->exceptionClass;
            $rows['Thrown at'] = $report->location;
        }

        $html = '<dl class="facts">';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $html .= '<div><dt>'.self::e($label).'</dt><dd>'.self::e($value).'</dd></div>';
        }

        $html .= self::reference($report, $settings);

        // The correlation id beside the trace id, and only when the two differ: with tracing off the
        // reference IS the correlation id, and a second row repeating it would teach a reader that the two
        // ids are interchangeable — which is the confusion keeping them apart exists to prevent.
        if ($report->correlationId !== '' && $report->correlationId !== $report->reference) {
            $html .= '<div><dt>Correlation</dt><dd>'.self::e($report->correlationId).'</dd></div>';
        }

        return $html.'</dl>';
    }

    /**
     * The reference, as ONE artefact a reader can take away.
     *
     * It was printed twice — in the 5xx sentence and in a REFERENCE cell — and neither copy could be
     * copied, so the single action a production page offers was "retype this uuid". Now the sentence points
     * here, the cell is `user-select:all` (one click takes the whole id, with no JavaScript at all, in
     * whatever a container's minimal browser turns out to be), and a copy button is offered on top of that
     * where the browser can honour one.
     *
     * The `<dt>`/`<dd>` pair is byte-for-byte what it was: it is what four tests and a support process both
     * read, and the affordance is added BESIDE it in a second `<dd>` — which a definition list allows and a
     * `<button>` loose inside a `<dl>` would not be.
     */
    private static function reference(ErrorReport $report, ErrorPageSettings $settings): string
    {
        if ($report->reference === '') {
            return '';
        }

        $id = self::e($report->reference);

        $action = $settings->copyButton
            ? '<button type="button" class="copy" hidden data-ref="'.$id.'">Copy</button>'
            : '';

        return '<div class="fact-ref"><dt>Reference</dt><dd>'.$id.'</dd>'
            .'<dd class="ref-act">'.$action.'<span class="hint">Quote this if you report the problem.</span></dd></div>';
    }

    /**
     * Ten lines of progressive enhancement, and the only script this page carries.
     *
     * THE BUTTON SHIPS HIDDEN AND THIS REVEALS IT. A control that does nothing is worse than no control, and
     * there are three ordinary ways for the clipboard to be unavailable BEFORE a click: scripts off, a
     * Content-Security-Policy that refuses an inline script, and a plain-http origin (navigator.clipboard is
     * a secure-context API). In every one of them the button stays hidden, the select-all cell is still
     * there, and the page is exactly what it was before.
     *
     * THOSE THREE ARE NOT ALL OF THEM, which is why the click has a rejection arm. `writeText()` rejects
     * with the API present and the guard already passed — the document is not focused (a plain DOMException,
     * and the common one), the `clipboard-write` permission is denied, an embedding page's Permissions-Policy
     * omits it — and those are exactly the cases where the control has already been REVEALED, so a bare
     * `.then()` leaves the one reader who gets here clicking a button that does nothing, silently, while the
     * console of the page whose whole job is to be quiet fills with an unhandled rejection. The button says
     * so instead, and stays clickable: an unfocused document is transient, a second click after the page has
     * focus succeeds, and the `user-select:all` cell beside it is the affordance that never needed a script.
     *
     * `firefly.web.error-page.copy-button` removes the control and this script entirely, for a deployment
     * whose CSP must report zero inline scripts.
     */
    private static function clipboard(ErrorPageSettings $settings, bool $rendered): string
    {
        if (! $settings->copyButton || ! $rendered) {
            return '';
        }

        return '<script>(function(){var b=document.querySelector(".copy");'
            .'if(!b||!navigator.clipboard){return}b.hidden=false;'
            .'b.addEventListener("click",function(){navigator.clipboard.writeText(b.getAttribute("data-ref")||"")'
            .'.then(function(){b.textContent="Copied"})'
            .'.catch(function(){b.textContent="Copy failed"})})})();</script>';
    }

    private static function detail(ErrorReport $report): string
    {
        if (! $report->detailed) {
            return '';
        }

        return self::previous($report).self::frames($report);
    }

    private static function previous(ErrorReport $report): string
    {
        if ($report->previous === []) {
            return '';
        }

        // The outermost message is usually the least specific one — firefly/web wraps a binding failure, the
        // container wraps a constructor throw — so the chain is shown in full and near the top rather than
        // buried under forty frames.
        $html = '<section class="panel"><h2>Caused by</h2><ol class="chain">';
        foreach ($report->previous as $link) {
            $html .= '<li><p class="cls">'.self::e($link['class']).'</p>'
                .'<p class="msg">'.self::e($link['message']).'</p>'
                .'<p class="loc">'.self::e($link['location']).'</p></li>';
        }

        return $html.'</ol></section>';
    }

    /**
     * The trace, split into the frames a reader came for and the ones they came through.
     *
     * A raw PHP trace is a hundred frames of which ten are the application's, and interleaving them is what
     * makes a trace something to scroll rather than something to read. So the application's frames are the
     * LIST — accented, in stack order, the first one with source already open — and the dependencies are a
     * single disclosure underneath, closed. Nothing is hidden: the count is on both, and one click or one
     * Enter opens the whole set.
     *
     * A STACK WITH NO APPLICATION FRAME IS NOT A REASON TO SHOW NOTHING. The split assumes there is
     * something above the disclosure to be a list, and `ErrorFrame::$vendor` is decided by `/vendor/` in
     * the path alone — so a stack has none whenever nothing application-owned is on it. Under php-fpm the
     * entry script keeps that from happening: `public/index.php` is the application's, so it is the bottom
     * frame of every request. It is exactly that floor a WORKER deployment removes — Octane, FrankenPHP
     * worker mode and Vapor all boot from a front controller inside `vendor/` — and there every failure
     * raised before application code runs (a routing miss, a 405, a container or bootstrap throw) has a
     * stack that is dependencies end to end. Closing the disclosure there left the panel as a heading over
     * one collapsed row with not a single frame in sight, which is a worse page than the interleaved trace
     * this split replaced. So the disclosure is OPEN when it is the only thing in the panel: "nothing is
     * hidden" has to hold in the case where hiding is all the page would otherwise do.
     *
     * Every frame keeps its position in the UNTRIMMED stack (`#37`), so a split list still reads as a stack
     * and a budgeted one still says where its gaps are.
     *
     * THE HEADER COUNTS THE STACK, NOT THE ROWS. `max-frames` trims the list in the report, before any
     * markup exists, so counting what is rendered would answer "7 of 40 in your code" for a stack of 104
     * and say nothing at all about the sixty-four frames that were dropped — a label that was honest
     * before the budget existed and became a quiet lie the moment it did. The untrimmed totals are carried
     * on the report for exactly this, and are named whenever they differ from what is shown; when nothing
     * was trimmed the "of" is left out, because "40 of 40" is a question a reader should not have to ask.
     */
    private static function frames(ErrorReport $report): string
    {
        if ($report->frames === []) {
            return '';
        }

        $own = [];
        $vendor = [];
        foreach ($report->frames as $frame) {
            if ($frame->vendor) {
                $vendor[] = $frame;
            } else {
                $own[] = $frame;
            }
        }

        $shown = count($report->frames);
        $summary = $shown === $report->frameCount
            ? $report->frameCount.' frames · '.$report->appFrameCount.' in your code'
            : $shown.' of '.$report->frameCount.' frames · '.$report->appFrameCount.' in your code';

        $html = '<section class="panel trace"><h2>Stack trace <span class="n">'.self::e($summary).'</span></h2>';

        if ($own !== []) {
            $html .= '<ol class="frames">';
            $opened = false;
            foreach ($own as $frame) {
                $html .= self::frame($frame, ! $opened && $frame->excerpt !== []);
                $opened = $opened || $frame->excerpt !== [];
            }
            $html .= '</ol>';
        }

        return $html.self::dependencies($vendor, $report->frameCount - $report->appFrameCount, $own === []).'</section>';
    }

    /**
     * One application frame: a disclosure when there is source to disclose, a plain row when there is not.
     *
     * `name="firefly-frame"` is HTML's own exclusive accordion — opening one closes the rest, with no
     * JavaScript and no CSS — and `<summary>` is focusable and Enter/Space-operable because it is a real
     * control. A browser too old for the attribute simply lets several be open, which is the behaviour this
     * page had before and is not a failure.
     */
    private static function frame(ErrorFrame $frame, bool $open): string
    {
        if ($frame->excerpt === []) {
            // No body to expand into, so it renders as a row rather than as a control that does nothing.
            return '<li class="own"><div class="row">'.self::row($frame).'</div></li>';
        }

        return '<li class="own"><details name="firefly-frame"'.($open ? ' open' : '').'>'
            .'<summary>'.self::row($frame).'</summary>'
            .self::excerpt($frame).'</details></li>';
    }

    /**
     * A frame on ONE LINE, whatever the width.
     *
     * The order is the order a reader scans: where in the stack, whose code, which directory, WHICH FILE,
     * which line, what was called.
     *
     * WHAT THE ELLIPSIS TAKES, AND IN WHICH ORDER. `text-overflow:ellipsis` always drops the END of a span,
     * so the only way to say which token gets shortened first is to give each one a span of its own. Four
     * do: the directory, the file name, the line, and the call — split again into the qualifier and the
     * FUNCTION, `->get()`, because a Laravel trace is sixty `Illuminate\…` frames whose method names are
     * the only difference between them, and printing the call as one span clipped exactly that away: sixty
     * rows reading `Illuminate\Database\Eloq…`.
     *
     * With the spans in place the CSS ranks them, and the ranking is the whole design: `.dir` first —
     * its innermost directories are a real loss, but the file name, the line and the package badge beside
     * it are enough to find the file — then `.cls`, which repeats a class name the file name already gave,
     * and only then `.fn`. The file name and the line never shorten at all. What a rank does NOT mean is
     * "cannot shrink": a span that refuses to shrink in a row that must keeps its full width and paints
     * past the row's edge, where it is cut with no ellipsis at all, so every rank here is a shrink factor
     * and `.fn`'s is simply the smallest. On a phone even the last rank is not spent: the row wraps and
     * gives the call a line of its own rather than take a character off it.
     */
    private static function row(ErrorFrame $frame): string
    {
        $package = $frame->package();
        $qualifier = $frame->callQualifier();

        return '<span class="ix">'.self::e('#'.$frame->index).'</span>'
            .($package === null ? '' : '<span class="pkg">'.self::e($package).'</span>')
            .'<span class="dir">'.self::e($frame->dir()).'</span>'
            .'<span class="base">'.self::e($frame->base()).'</span>'
            .'<span class="ln">'.($frame->line === null ? '' : self::e(':'.$frame->line)).'</span>'
            .'<span class="call">'
            .($qualifier === '' ? '' : '<span class="cls">'.self::e($qualifier).'</span>')
            .'<span class="fn">'.self::e($frame->callFunction()).'</span>'
            .'</span>';
    }

    /**
     * Every dependency frame behind one disclosure, with an honest count of what the budget left out.
     *
     * CLOSED IS A CHOICE ABOUT CONTEXT, NOT A PROPERTY OF THIS SET. It is right whenever the application's
     * frames are above it, because then the reader has the frames they came for and this is the stack they
     * came THROUGH. When there are none — a routing miss, a 405, anything thrown before application code
     * runs, or any failure at all under a front controller that lives in `vendor/` — closing it makes the
     * trace panel a heading and a collapsed row with no frame visible at all, so `$open` is passed in by
     * the caller rather than decided here: this set does not know whether it is the context or the whole
     * trace.
     *
     * @param  list<ErrorFrame>  $vendor
     * @param  int  $total  dependency frames in the UNTRIMMED stack
     * @param  bool  $open  true when this disclosure is the only thing in the panel
     */
    private static function dependencies(array $vendor, int $total, bool $open): string
    {
        if ($vendor === []) {
            return '';
        }

        $label = $total.' frame'.($total === 1 ? '' : 's').' in your dependencies';
        $note = count($vendor) === $total ? '' : '<span class="dn">'.self::e(count($vendor).' shown').'</span>';

        $html = '<details class="deps"'.($open ? ' open' : '').'><summary><span class="dsum">'.self::e($label).'</span>'.$note.'</summary>'
            .'<ol class="frames deps-list">';

        foreach ($vendor as $frame) {
            $html .= '<li class="vendor"><div class="row">'.self::row($frame).'</div></li>';
        }

        return $html.'</ol></details>';
    }

    private static function excerpt(ErrorFrame $frame): string
    {
        $html = '<table class="src">';
        foreach ($frame->excerpt as $number => $text) {
            $hit = $number === $frame->line ? ' class="hit"' : '';
            $html .= '<tr'.$hit.'><td class="ln">'.self::e((string) $number).'</td><td class="ln-src">'.self::e($text).'</td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * The footer explains the page itself, and only where that is a safe thing to explain: on a
     * non-production environment. In production it says nothing — naming the framework and a config key to
     * an anonymous visitor is a free hint about the stack, and the error code above is the only thing that
     * page's reader actually needs.
     */
    private static function footer(ErrorReport $report, ErrorPageSettings $settings): string
    {
        if (! $settings->hints) {
            return '';
        }

        $note = $report->detailed
            ? 'Details are shown because <code>firefly.web.error-page.trace</code> is on (it follows <code>app.debug</code>). Turn either off and this page shows only the status and the code.'
            : 'Set <code>APP_DEBUG=true</code>, or <code>firefly.web.error-page.trace</code>, to see the exception and its stack trace here.';

        return '<footer class="foot"><p>'.$note.'</p>'
            .'<p class="muted">The same failure is served as <code>application/problem+json</code> to a client that asks for JSON.</p></footer>';
    }

    /**
     * A short, honest sentence for a production page — no message, no internals.
     *
     * The 5xx sentence POINTS AT the request reference, because that is the ONE thing a reader of a
     * production page can do about a failure they cannot see: quote the id, so an operator can find the log
     * line it stamps. The problem document has said "quote reference <id>" since it carried `traceId`; the
     * page a person actually looks at said only that the error had been logged, which left them nothing to
     * quote.
     *
     * IT NO LONGER MIRRORS ProblemMapper::OPAQUE_WITH_REFERENCE, and the divergence is deliberate rather
     * than drift. This sentence used to name the id inline, word for word as the document does, so a ticket
     * read the same whichever form the failure was seen in — but that printed the same uuid twice on every
     * production page, in prose and in the Reference cell, with no way to copy either. The page now prints
     * it ONCE, in a cell that is `user-select:all` and may carry a Copy button (see reference()), and the
     * lede points there. A problem document has no cell to point at, so it keeps the id inline and keeps its
     * own wording. What the two surfaces still share is the ID ITSELF — the value a trace search resolves
     * and a ticket is filed with — and it is only the SENTENCE that stopped being a cross-surface
     * invariant. ErrorPageTest pins that divergence against the constant, so it cannot be quietly re-decided
     * in either direction.
     */
    private static function reassurance(int $status, string $reference): string
    {
        return match (true) {
            $status === 404 => 'That page does not exist.',
            $status === 403 => 'You do not have access to that.',
            $status === 401 => 'You need to sign in to see that.',
            $status === 405 => 'That address does not accept this kind of request.',
            $status >= 500 => $reference === ''
                ? 'Something went wrong on our side. The error has been logged.'
                : 'Something went wrong on our side. It has been logged; quote the reference below if you report it.',
            default => 'That request could not be completed.',
        };
    }

    private static function favicon(): string
    {
        return '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 32 32\'%3E%3Ccircle cx=\'16\' cy=\'16\' r=\'9\' fill=\'%23e07a17\'/%3E%3C/svg%3E">';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
:root{
  color-scheme:light;
  --bg:#f7f6f3; --panel:#fff; --panel-2:#faf9f6; --line:#e7e3db; --line-2:#d6d0c4;
  --ink:#20242a; --ink-2:#5f6672; --ink-3:#696f7d;
  --brand:#e07a17;
  /* The brand as TEXT. #e07a17 is a 3.01:1 foreground on white — fine for a 9px dot or a 3px rail, and
     unreadable for the exception class it was being used on. Shapes and text need different oranges. */
  --brand-ink:#a1520a;
  --down:#c02717; --down-bg:#fbe9e7; --warn:#9a6206; --warn-bg:#fdf1dd;
  --idle:#6b7280; --idle-bg:#f0f0f2;
  --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --r:12px;
}
@media (prefers-color-scheme: dark){
  :root{
    color-scheme:dark;
    --bg:#0f1214; --panel:#15191c; --panel-2:#181d21; --line:#252c32; --line-2:#333c44;
    --ink:#e8ecef; --ink-2:#9aa5af; --ink-3:#828e99;
    --brand:#ff9d3c;
    --brand-ink:#ff9d3c;
    --down:#ff8a7a; --down-bg:#2a1614; --warn:#ffc266; --warn-bg:#2a2114;
    --idle:#9aa5af; --idle-bg:#1c2226;
  }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 var(--sans);-webkit-font-smoothing:antialiased}
code{font-family:var(--mono);font-size:.92em;background:var(--panel-2);border:1px solid var(--line);border-radius:5px;padding:1px 5px}
.sheet{max-width:960px;margin:0 auto;padding:56px 20px 72px;display:flex;flex-direction:column;gap:22px;min-width:0}
.head{display:flex;flex-direction:column;gap:10px}
.mark{display:inline-flex;align-items:center;gap:9px;font-weight:650;letter-spacing:-.01em;color:var(--ink-2);margin-bottom:14px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--brand);flex:none;box-shadow:0 0 0 3px color-mix(in srgb, var(--brand) 18%, transparent)}
.status{display:flex;align-items:baseline;gap:12px;margin:0;flex-wrap:wrap}
.status b{font-size:64px;line-height:1;letter-spacing:-.04em;font-variant-numeric:tabular-nums}
.status span{font-size:19px;font-weight:600;color:var(--ink-2)}
.status.down b{color:var(--down)} .status.warn b{color:var(--warn)} .status.idle b{color:var(--idle)}
.code{margin:0;font-family:var(--mono);font-size:13px;letter-spacing:.04em;color:var(--ink-2)}
.message{margin:6px 0 0;font-size:16px;line-height:1.5;color:var(--ink);overflow-wrap:anywhere}
.acts{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.act{display:inline-flex;align-items:center;font-size:13.5px;font-weight:600;text-decoration:none;padding:7px 14px;border-radius:8px;border:1px solid var(--line-2);color:var(--ink);background:var(--panel)}
.act:hover{border-color:var(--ink-3)}
.act:focus-visible{outline:2px solid var(--brand-ink);outline-offset:2px}
.act.primary{background:var(--ink);color:var(--panel);border-color:var(--ink)}
.act.primary:hover{opacity:.9}
.muted{color:var(--ink-2)}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0;margin:0;background:var(--panel);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
/* Each cell draws its own rule up and to the left. The first row's and first column's shadows fall outside
   the padding box and are clipped by overflow:hidden, so nothing doubles the container border — and a
   ragged last row is panel-coloured rather than the dead beige the line-coloured ground used to show. */
.facts>div{background:var(--panel);padding:11px 14px;min-width:0;box-shadow:-1px -1px 0 var(--line)}
.facts dt{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-2);margin:0 0 3px}
.facts dd{margin:0;font-family:var(--mono);font-size:12.5px;overflow-wrap:anywhere}
/* .fact-ref is a div child of .facts, so it already has the cell's ground, padding and rule. Only what
   makes it the REFERENCE cell is declared here. One click takes the whole id — no JavaScript at all, and
   no dragging a selection across a wrapped uuid. */
.fact-ref dd:first-of-type{user-select:all;-webkit-user-select:all}
.fact-ref .ref-act{display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap}
.fact-ref .hint{font-family:var(--sans);font-size:11.5px;color:var(--ink-2)}
.copy{font:inherit;font-size:11.5px;font-family:var(--sans);color:var(--ink);background:var(--panel-2);border:1px solid var(--line-2);border-radius:6px;padding:2px 9px;cursor:pointer}
.copy:hover{background:var(--bg)}
/* The ring is --brand-ink, not --brand: the button's ground is --panel-2, where #e07a17 measures
   2.86:1 — under SC 1.4.11's 3:1 floor for a non-text indicator. Same token, same reason, as the
   two summary rings below. */
.copy:focus-visible{outline:2px solid var(--brand-ink);outline-offset:1px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;min-width:0}
.panel h2{margin:0;padding:12px 16px;font-size:13px;font-weight:650;border-bottom:1px solid var(--line);background:var(--panel-2);display:flex;justify-content:space-between;gap:12px;align-items:baseline}
.panel h2 .n{font-weight:400;font-size:11.5px;color:var(--ink-3);font-family:var(--mono)}
.chain{list-style:none;margin:0;padding:0}
.chain li{padding:12px 16px;border-bottom:1px solid var(--line)}
.chain li:last-child{border-bottom:0}
.chain .cls{margin:0;font-family:var(--mono);font-size:12.5px;color:var(--brand-ink);overflow-wrap:anywhere}
.chain .msg{margin:3px 0 0;overflow-wrap:anywhere}
.chain .loc{margin:3px 0 0;font-family:var(--mono);font-size:12px;color:var(--ink-3);overflow-wrap:anywhere}
.frames{list-style:none;margin:0;padding:0}
.frames li{border-bottom:1px solid var(--line)}
.frames li:last-child{border-bottom:0}
/* ONE LINE PER FRAME. The old rule was flex-wrap:wrap with overflow-wrap:anywhere on the path and
   margin-left:auto on the call, so every row became a wrapped path plus a third line for the call — an
   87px pitch that made a hundred-frame trace 10,108 pixels tall. Only .dir and .cls may shrink. */
.frames .row,.frames summary{display:flex;gap:8px;align-items:baseline;padding:7px 16px;min-width:0;flex-wrap:nowrap;white-space:nowrap}
.frames summary{cursor:pointer;list-style:none}
.frames summary::-webkit-details-marker{display:none}
.frames summary::before{content:"▸";color:var(--ink-3);font-size:10px;flex:none}
.frames details[open] summary::before{content:"▾"}
/* THE RING IS DRAWN IN --brand-ink, NOT --brand. A focus indicator is a non-text contrast target: WCAG
   2.1 SC 1.4.11 asks for 3:1 against what it sits on, and #e07a17 measures 3.01:1 on a white panel and
   2.86:1 on --panel-2, which is exactly where the dependency disclosure's ring is drawn. Passing by 0.01
   on one ground and failing on the other is not a contrast decision, it is an accident. --brand-ink is
   the token this file already keeps for the brand as a foreground: 5.63:1 and 5.35:1 on those two grounds
   in light, and identical to --brand in dark, where both are #ff9d3c. */
.frames summary:focus-visible{outline:2px solid var(--brand-ink);outline-offset:-2px}
.frames .ix{font-family:var(--mono);font-size:11px;color:var(--ink-3);flex:none;min-width:2.8em;text-align:right}
.frames .pkg{font-size:11px;line-height:1.7;color:var(--ink-2);background:var(--panel-2);border:1px solid var(--line);border-radius:5px;padding:0 5px;flex:none;max-width:13em;overflow:hidden;text-overflow:ellipsis}
.frames .dir{font-family:var(--mono);font-size:12.5px;color:var(--ink-2);min-width:0;flex:0 100 auto;overflow:hidden;text-overflow:ellipsis}
.frames .base{font-family:var(--mono);font-size:12.5px;color:var(--ink);flex:none}
.frames .ln{font-family:var(--mono);font-size:12.5px;color:var(--ink-2);flex:none}
/* The call is the path's twin: .cls is the qualifier and may be ellipsised, .fn is the method name and is
   the token that tells sixty Illuminate frames apart, so it is the LAST thing the row gives up. A nested
   flex rather than two row items, so the pair stays glued (the row's 8px gap would print
   `Collection ->each()`).

   WHAT "LAST" IS MADE OF, because `flex:none` did not mean it. An unshrinkable span inside a shrinking row
   does not stay WHOLE, it stays WIDE: its box keeps its content width, its own `text-overflow` therefore
   never has a narrower box to draw an ellipsis in, and the glyphs simply run out of the row and are cut by
   `.panel{overflow:hidden}` with nothing to mark the cut. Measured in Chrome at 375px against a 35-frame
   Laravel trace, 34 of 35 rows painted their method name up to 138px beyond the panel's right edge, and
   `->whereHasMorphRelationship` arrived on screen as `->wh`.
   So the ORDER is stated with shrink factors rather than by refusing to shrink: .dir and .cls shrink at
   100 and .fn at 1, which spends the two discardable spans down to nothing before flexbox takes a single
   character off the method name — and `overflow:hidden` here means that whatever is taken is taken inside
   the row, with an ellipsis, instead of outside it in silence. At a 560-920px panel that ranking leaves
   every method name whole except the closure descriptor below. */
.frames .call{font-family:var(--mono);font-size:12px;color:var(--ink-3);margin-left:auto;min-width:0;flex:0 1 auto;display:flex;align-items:baseline;overflow:hidden}
.frames .call .cls{min-width:0;flex:0 100 auto;overflow:hidden;text-overflow:ellipsis}
/* The cap is the one guard: PHP 8.4 names a closure `{closure:/abs/path/file.php:14}`, so a "function
   name" can be a hundred characters of absolute path. It sits far above any real method name and bites
   only that case, which would otherwise take the whole row's width for one frame's descriptor. */
.frames .call .fn{min-width:0;flex:0 1 auto;max-width:24em;overflow:hidden;text-overflow:ellipsis}
/* The application's own frames are the point of the page; the dependency ones are context. */
.frames li.own{border-left:3px solid var(--brand);background:var(--panel)}
.frames li.own .base{font-weight:600}
.frames li.vendor{border-left:3px solid transparent;background:var(--panel-2)}
/* De-emphasised by weight, ground and the missing rail — NOT by fading text below readable contrast. */
.frames li.vendor .base{font-weight:400;color:var(--ink-2)}
.deps{border-top:1px solid var(--line);background:var(--panel-2)}
/* …except when it follows the heading directly, which is the vendor-only stack: the heading already draws
   a border-bottom, and two adjacent 1px rules paint as one 2px one under a heading and nowhere else. */
.panel h2+.deps{border-top:0}
.deps>summary{display:flex;gap:12px;align-items:baseline;padding:10px 16px;cursor:pointer;list-style:none;font-size:12.5px;color:var(--ink-2)}
.deps>summary::-webkit-details-marker{display:none}
.deps>summary::before{content:"▸";color:var(--ink-3);font-size:10px}
.deps[open]>summary::before{content:"▾"}
/* The one control a keyboard reader MUST operate to reach the dependency frames, on --panel-2. */
.deps>summary:focus-visible{outline:2px solid var(--brand-ink);outline-offset:-2px}
.deps .dn{margin-left:auto;font-family:var(--mono);font-size:11.5px;color:var(--ink-3)}
.deps-list{border-top:1px solid var(--line)}
.src{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:12.5px;background:var(--panel-2);border-top:1px solid var(--line);display:block;overflow-x:auto}
.src tr{display:table;width:100%;table-layout:fixed}
.src td{padding:2px 10px;white-space:pre;vertical-align:top}
.src .ln{width:56px;text-align:right;color:var(--ink-2);user-select:none;border-right:1px solid var(--line)}
.src .ln-src{overflow-wrap:normal}
.src tr.hit{background:color-mix(in srgb, var(--brand) 14%, transparent)}
.src tr.hit .ln{color:var(--brand-ink);font-weight:700}
.foot{color:var(--ink-2);font-size:12.5px;display:flex;flex-direction:column;gap:5px}
.foot p{margin:0}
@media (max-width:560px){
  .sheet{padding:32px 14px 48px}
  .status b{font-size:48px}
  .frames .pkg{display:none}
  /* The qualifier goes entirely, before the method name loses a character: on a phone row
     `Illuminate\Database\Eloquent\Builder` is what `Builder.php` two columns to its left already said,
     and `->get()` is not said anywhere else. */
  .frames .call .cls{display:none}
  /* AND THEN THE PHONE ROW WRAPS, because at 375px it provably cannot do both. A row has 295px there, and
     `AddQueuedCookiesToResponse.php` — a real Laravel file name, which never shortens — is 226 of them;
     `:46` and `->handle` have to go somewhere. Ranked shrinking has an answer for that and it is the wrong
     one: measured at 375px it spent .fn down until 23 of 35 method names had lost characters and two had
     lost all of them, rendering at zero width. So the phone takes the other branch — one more line instead
     of a shorter name — and the directory goes with .pkg and .cls, because it was already ellipsised to
     `vendor/la…` on every row at this width and dropping it is what holds the wrapped row to two lines
     instead of the four a full-width .dir forces (measured: 1,961px of trace against 3,406px).
     This is NOT the pre-wave rule that made a hundred frames 10,108 pixels tall. That one wrapped the PATH
     ITSELF, with overflow-wrap:anywhere, at 87px a row; nothing here wraps inside a span, and the break
     can only fall between two whole tokens. */
  .frames .dir{display:none}
  .frames .row,.frames summary{flex-wrap:wrap}
  .frames .call{margin-left:0}
  .frames .ix{min-width:2.2em}
  /* The call keeps the 24em guard it has everywhere and no tighter one. A phone used to cap it at 14em,
     which was the right cap while the call had to share a line with a file name and was the wrong one the
     moment it stopped: on its own 295px line, 14em cut `->sendRequestThroughRouter` and
     `->whereHasMorphRelationship` for nothing. 24em still fits that line, and still bites the closure
     descriptor it exists for. */
}
CSS;
    }
}
