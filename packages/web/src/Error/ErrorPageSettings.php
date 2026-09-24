<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use Firefly\Config\Config;
use Illuminate\Support\Str;

/**
 * What the HTML error page shows, and whether it shows at all.
 *
 * TWO KEYS, AND THE SECOND ONE IS THE ONE THAT MATTERS. `enabled` decides whether a browser gets a LaraFly
 * page or Laravel's stock one; `trace` decides whether that page carries the exception's message, its file
 * and line, a source excerpt and a stack trace. They are separate because they answer different questions:
 * the first is a branding choice and the second is a disclosure, and an application that wants the first in
 * production must not get the second by accident.
 *
 * TWO MORE KEYS EXIST FOR THE TWO THINGS APPLICATIONS ACTUALLY WANT TO CHANGE. `json-paths` names the URL
 * space that is a MACHINE surface and must answer with a problem document whatever the caller's Accept
 * header says — it defaults to `api/*`, because a developer opening an API URL in a browser wants to see the
 * payload their client will get, not a styled page telling them the endpoint renders HTML. `views` hands a
 * status (or `default`) to the application's own Blade view, so a public 404 can be the product's own page
 * while a 500 in staging is still the framework's diagnostic one.
 *
 * `problem-paths` IS NOT A KEY AND `problem-fallback` IS. The question `json-paths` answers is "which URLs
 * are machine surfaces"; the question this one answers is "what does a caller who named nothing get". They
 * are different questions and only the first is about the URL. With the fallback on — the default, and what
 * the documentation has always claimed — a request carrying a WILDCARD Accept header, or no Accept at all,
 * is answered with the problem document, because that is the form a client can read and the page is for a
 * person who asked for one. Off, such a request falls through to Laravel's handler exactly as it used to.
 *
 * `trace` DEFAULTS TO `app.debug` and is enforced at render time, not merely at template time — the renderer
 * builds no frame list, opens no source file and copies no exception message when it is off. That is
 * deliberate: a page that assembled the details and then declined to print them would put a stack trace one
 * misplaced `@if` away from the response body, and the file reads alone are a reason to not do the work.
 *
 * `disclose` IS THE PROBLEM DOCUMENT'S OWN GATE, AND IT DOES NOT FOLLOW `app.debug`. For one release the
 * JSON renderer shared `trace`, and that was the wrong gate for a machine surface: every local and compose
 * environment sets APP_DEBUG, so a console fed by problem+json rendered a duplicate-key insert as the DSN,
 * the tenant id, the acting user and the full statement in a red banner — the HTML page next to it withheld
 * everything, because a developer with debug on is looking at a page, not at the payload a client parses. A
 * person who wants a driver message inside a JSON `detail` says so with `firefly.web.problem.disclose=true`;
 * nothing infers it. The two gates are independent so that turning one on never opens the other.
 *
 * WHAT PRODUCTION SEES with `trace` off is the status, the reason phrase, the stable error code and the
 * request's reference — the same `code` and `traceId` the problem+json carries, so a user can quote them
 * into a support ticket and an operator can find them in the log. Never the RAW exception message: that
 * sentence was written for a developer and routinely names a table, a column, a class or an id. And not the
 * footer's own advice about turning the trace on, which is useful on a staging box and is a free hint about
 * the stack to anyone else — see `hints`.
 *
 * THE ONE ADDITION IS AUTHORED, IT HAS ITS OWN KEY, AND IT IS THE PAGE'S LEDE. With `authored-detail` on
 * (the default) the production page says the sentence the application ITSELF wrote for a caller — a
 * FireflyException's "Order 42 does not exist.", or an `abort(404, 'No such tenant.')` — carried on the
 * report as `ErrorReport::$publicDetail`, because that is exactly what the problem document beside it
 * publishes as `detail`, and one failure reading two ways depending on which surface answered is its own
 * kind of bug. None of this is an exemption from the paragraph above: ProblemMapper decides what counts as
 * authored, withholds everything at 500 and above, and replaces the sentences the FRAMEWORK generated —
 * the router's "The route … could not be found." and the route-model-binding 404s Laravel rewrites into
 * it, which name a model class and a primary key. Turn the key off and `$publicDetail` is '', the lede
 * goes back to the generic reassurance for the status, and there is no authored sentence for any renderer
 * — this page or an application's own error view, which is handed the same report — to reach for at all.
 *
 * A BARE `abort(403)` AUTHORED NOTHING, and the page reads it that way whatever this key says. The REPORT
 * still carries "Forbidden", because that is what the problem document publishes as `detail` and this
 * property mirrors the document; the PAGE declines to lede with a word already printed beside the status
 * code, and says "You do not have access to that." instead. That rule lives in
 * ErrorPage::authoredSentence(), on the page, because it is a judgement about what a person is told rather
 * than about what a caller is sent.
 *
 * WITH ONE EXCEPTION, AND IT IS NOT AN AUTHORED SENTENCE. A 405 the router raised keeps its verb sentence
 * — "That address does not accept a GET request. It accepts POST." — whichever way this key is set, because
 * nothing in it came from the application: ProblemMapper reads the verbs off the `Allow` header the ROUTER
 * put on its own exception, and ProblemMapper::methodSentence() writes the words for the page and for the
 * document alike. This key governs the DISCLOSURE of what an
 * application said, and there is none to govern there — the same list of verbs is the `allowed` member of
 * the problem document published for the same failure. An operator who turns the key off for the
 * status-and-code page gets it everywhere else and gets this sentence still.
 */
final readonly class ErrorPageSettings
{
    /**
     * WHERE A READER CAN GO NEXT — and the three values this class refuses to hold a hostile spelling of.
     *
     * THESE THREE ARE NOT PROMOTED, AND THAT IS THE WHOLE POINT. Each one is destined for an `href` on a
     * page the framework hands itself, so the guard has to run on every construction path rather than on
     * the one that happens to read configuration: `firefly/security` builds an ErrorPageSettings by hand
     * for its login page, and a dozen tests construct one directly. A promoted property would put the
     * caller's string into the object with nothing in between, and the guarantee this class advertises —
     * that it cannot HOLD an unsafe URL, whoever built it — would have been true only of fromConfig(). The
     * constructor body assigns each of them through self::url(), so it is true of all of them.
     *
     * WHAT PRINTS THEM is the action row in ErrorPage::actions(), which offers the one that fits the status
     * — `signIn` on a 401, `home` and `support` wherever they are set — and offers nothing it was not
     * given: an empty value produces no link rather than a guessed route name. The row was written against
     * properties that were already safe, which is the point of guarding here instead of at the point of
     * printing. See self::url() for the vocabulary, and `actions` below for switching the row off entirely.
     */
    public string $home;

    public string $signIn;

    public string $support;

    /**
     * @param  list<string>  $jsonPaths  path patterns that are answered as problem+json whatever the client asked for
     * @param  array<string, string>  $views  status (or `default`) => the Blade view to render instead
     * @param  string  $home  the "Go home" target; '' offers no link. Guarded: see self::url()
     * @param  string  $signIn  the 401's sign-in target; '' offers no link. Guarded: see self::url()
     * @param  string  $support  the "Contact support" target; '' offers no link. Guarded: see self::url()
     * @param  bool  $actions  whether the page offers any navigation at all
     */
    public function __construct(
        public bool $enabled = true,
        public bool $trace = false,
        public string $title = 'LaraFly',
        public int $excerptLines = 7,
        public bool $hints = false,
        public array $jsonPaths = ['api/*'],
        public array $views = [],
        public bool $disclose = false,
        string $home = '/',
        string $signIn = '',
        string $support = '',
        public bool $actions = true,
        // HOW MANY FRAMES THE PAGE BUILDS AT ALL. Applied as a trim in ErrorReport, before markup: a page
        // that renders a hundred frames and hides ninety has still escaped and shipped a hundred.
        public int $maxFrames = 40,
        // Whether the report CARRIES the sentence the problem document publishes, so a page can use it as
        // its lede. Off, `ErrorReport::$publicDetail` is '' and there is nothing for a renderer to print.
        // What counts as authored is ProblemMapper's decision, not this key's — see the class comment.
        public bool $authoredDetail = true,
        // Progressive enhancement, and the only script this page has ever carried: see ErrorPage::clipboard().
        public bool $copyButton = true,
        // Whether a caller that named NOTHING acceptable gets a problem document rather than Laravel's own
        // page. See ErrorPageRenderer::rendersProblem() for the case this closes.
        public bool $problemFallback = true,
    ) {
        $this->home = self::url($home);
        $this->signIn = self::url($signIn);
        $this->support = self::url($support);
    }

    /**
     * Whether $path is one this application serves as an API, and therefore must answer with a problem
     * document even when a browser asked for HTML.
     *
     * Accept-negotiation alone gets this wrong in one common case: a developer opens an API URL in a browser
     * to see what it returns, and gets a styled page instead of the payload their client will receive. Worse,
     * anything that follows a link into an API — a webhook debugger, a docs example, a curl with a copied
     * browser header — is told the endpoint renders HTML. A path prefix is the one signal that says "this
     * URL is a machine surface" independently of who is asking, which is why it OVERRIDES the header rather
     * than merely contributing to it.
     */
    public function isJsonPath(string $path): bool
    {
        $path = trim($path, '/');

        foreach ($this->jsonPaths as $pattern) {
            if (Str::is(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    /** The application's own view for this status, when it declared one. */
    public function viewFor(int $status): ?string
    {
        foreach ([(string) $status, 'default'] as $key) {
            if (array_key_exists($key, $this->views)) {
                return $this->views[$key];
            }
        }

        return null;
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            enabled: $config->bool('firefly.web.error-page.enabled', true),
            // The debug flag is the framework-wide statement of "this is a place where internals may be
            // shown". Following it means an application already configured correctly needs no new key, and
            // one that sets this key explicitly wins in both directions.
            trace: $config->bool('firefly.web.error-page.trace', $config->bool('app.debug', false)),
            title: $config->string('firefly.web.error-page.title', $config->string('app.name', 'LaraFly')),
            // Clamped rather than trusted: this is a radius around the throwing line, and a huge one turns
            // an error page into a source-code dump of the whole file.
            excerptLines: max(0, min(40, $config->int('firefly.web.error-page.excerpt-lines', 7))),
            // Whether the page may explain ITSELF — "set APP_DEBUG to see the trace". That sentence is
            // guidance for a developer on a box with debug off, and an unnecessary disclosure on a public
            // one: it names the framework and a config key to an anonymous visitor who asked for a page.
            // Environment is the right gate rather than `trace`, because a staging box legitimately runs
            // with debug off and is not the public internet.
            hints: $config->string('app.env', 'production') !== 'production',
            jsonPaths: self::patterns($config->string('firefly.web.error-page.json-paths', 'api/*')),
            views: self::views($config->array('firefly.web.error-page.views', [])),
            // Explicit, and only explicit: no fallback to app.debug, no fallback to `trace`. See the class
            // comment for the leak that a shared gate produced.
            disclose: $config->bool('firefly.web.problem.disclose', false),
            // Handed over RAW: the constructor runs each of these through url(), so this call site cannot
            // be the one that forgets. The default home is the site root, because a page with no way off it
            // is the state every one of these screenshots was in.
            home: $config->string('firefly.web.error-page.home', '/'),
            signIn: $config->string('firefly.web.error-page.sign-in', ''),
            support: $config->string('firefly.web.error-page.support', ''),
            actions: $config->bool('firefly.web.error-page.actions', true),
            // Clamped rather than trusted, like excerpt-lines above it: 0 would render a trace with no
            // frames in it, and a million would put the 10,108-pixel page back.
            maxFrames: max(1, min(500, $config->int('firefly.web.error-page.max-frames', 40))),
            authoredDetail: $config->bool('firefly.web.error-page.authored-detail', true),
            copyButton: $config->bool('firefly.web.error-page.copy-button', true),
            problemFallback: $config->bool('firefly.web.error-page.problem-fallback', true),
        );
    }

    /**
     * @return list<string>
     */
    private static function patterns(string $csv): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $csv)), static fn (string $p): bool => $p !== ''));
    }

    /**
     * @param  array<mixed>  $configured
     * @return array<string, string>
     */
    private static function views(array $configured): array
    {
        $views = [];

        foreach ($configured as $status => $view) {
            if (is_string($view) && $view !== '') {
                $views[(string) $status] = $view;
            }
        }

        return $views;
    }

    /**
     * A URL this page will put in an `href`, or '' when it is not one.
     *
     * IT IS PUBLIC BECAUSE IT IS THE PACKAGE'S WHOLE VOCABULARY FOR "SAFE HERE", and there is one href on
     * the page that is not operator-supplied: the "Try again" link, which is the address the REQUEST was
     * sent to. That one skipped this method in its first spelling and trusted Laravel's `path()` instead —
     * and `path()` will hand back `\evil.example` for a REQUEST_URI of `/\evil.example`, because Symfony
     * rejects a backslash in a request target only in `Request::create()`, never in the `prepareRequestUri()`
     * path a real request takes. Prefixed with a slash that is `/\evil.example`, which the paragraph below
     * spends nine lines explaining is an authority wearing a path's clothes. A second guard would have been
     * a second thing to keep right; ErrorPage::retry() calls THIS one and refuses any address it alters or
     * drops. The constructor's three assignments below are the other callers.
     *
     * THE ATTACK THIS CLOSES. These values arrive from configuration, which in a real deployment means a
     * templated environment variable — a Helm value, a CI-rendered .env, a tenant-provisioning job. The page
     * runs them through htmlspecialchars, which escapes quotes and angle brackets and does NOTHING to a
     * scheme: `javascript:alert(document.cookie)` reaches the DOM intact, on the application's own origin,
     * on a page a person opens while already confused. That is stored XSS the framework hands itself.
     *
     * THE ALLOW-LIST IS THE WHOLE VOCABULARY, and it is deliberately small. An ABSOLUTE PATH (`/login`) is
     * the normal answer and cannot carry a scheme. An `http(s)://` URL is the other one. Everything else is
     * dropped: `data:` and `vbscript:` are the other two script-bearing schemes, `file:` is not a link a
     * browser should follow from here, `mailto:` is a legitimate wish that this page does not serve (put the
     * address behind an https support URL), and a protocol-relative `//host/…` is refused because it
     * silently leaves the origin — which on an error page is indistinguishable from a phishing redirect.
     *
     * A PATH IS ONLY A PATH IF A BROWSER READS IT AS ONE, and two rules of the URL standard make that a
     * narrower set than "begins with a slash". For a special scheme the parser's relative-slash state treats
     * `\` EXACTLY LIKE `/`, so `/\host/…` is the protocol-relative case wearing a different separator:
     * Chrome, Firefox and Safari all resolve `/\evil.test/phish` against this origin as
     * `https://evil.test/phish`, which is the classic bypass of a filter that only looks for `//`. And
     * before any of that the parser DELETES every ASCII tab, LF and CR from the input, so `/<TAB>/evil.test`
     * IS `//evil.test` by the time anything reads it. Both are refused: the second character of a path may
     * not open an authority, and a value carrying INSIDE it a character the parser would delete is DROPPED
     * rather than normalised — a guard that keeps a string the browser will re-read differently has decided
     * nothing. `/` alone, the default home, is the one path with no second character and is kept by name.
     *
     * Refusing those characters instead of stripping them is also what keeps the scheme test honest.
     * `java\tscript:` is only a javascript: URL because a browser strips the tab; this method never has to
     * decide what the browser means by it, because the value is gone before either branch runs.
     *
     * THE EDGES ARE THE PARSER'S BUSINESS, AND TRIMMING THEM IS THAT SAME RULE READ PROPERLY rather than a
     * softening of it. Before it does anything else the standard strips every LEADING and TRAILING C0
     * control and space from the input, so a value padded at its edges is re-read by the browser as EXACTLY
     * the value this method would have allowed: nothing is left undecided, and refusing it would delete a
     * link an operator configured over a character no reader will ever see. The deployment mechanism this
     * method exists for is the one that adds them — a Helm block scalar and a here-doc-rendered `.env` both
     * end in a newline, and Laravel's `Env` does not trim a REAL environment variable the way Dotenv trims
     * a `.env` line — and a trailing space was already being KEPT verbatim here while the newline spelling
     * of the same padding dropped the whole link. So the edges are trimmed first and every refusal below is
     * about the INTERIOR. That gives up no ground, because each hostile value trims into another this
     * method already refuses: `<SP>//evil.test` into `//evil.test`, `<NUL>/\evil.test` into `/\evil.test`,
     * `<TAB>javascript:…` into `javascript:…`.
     */
    public static function url(string $value): string
    {
        $value = trim($value, "\x00..\x20");

        if ($value === '' || strpbrk($value, "\t\n\r") !== false) {
            return '';
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return $value === '/' || preg_match('#^/[^/\\\\]#', $value) === 1 ? $value : '';
    }
}
