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
 * `trace` DEFAULTS TO `app.debug` and is enforced at render time, not merely at template time — the renderer
 * builds no frame list, opens no source file and copies no exception message when it is off. That is
 * deliberate: a page that assembled the details and then declined to print them would put a stack trace one
 * misplaced `@if` away from the response body, and the file reads alone are a reason to not do the work.
 *
 * WHAT PRODUCTION SEES with `trace` off is the status, the reason phrase and the stable error code — the
 * same `code` the problem+json carries, so a user can quote it into a support ticket and an operator can
 * find it in the log. Not the message: an exception message is written for a developer and routinely names
 * a table, a column, a class or an id. And not the footer's own advice about turning the trace on, which is
 * useful on a staging box and is a free hint about the stack to anyone else — see `hints`.
 */
final readonly class ErrorPageSettings
{
    /**
     * @param  list<string>  $jsonPaths  path patterns that are answered as problem+json whatever the client asked for
     * @param  array<string, string>  $views  status (or `default`) => the Blade view to render instead
     */
    public function __construct(
        public bool $enabled = true,
        public bool $trace = false,
        public string $title = 'LaraFly',
        public int $excerptLines = 7,
        public bool $hints = false,
        public array $jsonPaths = ['api/*'],
        public array $views = [],
    ) {}

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
}
