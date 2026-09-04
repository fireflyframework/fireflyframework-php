<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Firefly (LaraFly) configuration reference
|--------------------------------------------------------------------------
|
| Every `firefly.*` key the framework actually reads is listed here, grouped by the package that reads
| it, with the real default the code falls back to when the key is absent. Keys that a typical
| application never touches are left COMMENTED OUT with their default shown, so the file stays short
| enough to read while remaining a complete reference: an absent key and a key set to the value printed
| next to it behave identically.
|
| Reading conventions used throughout:
|
|   * "default X" is the literal fallback in the call site, not an aspiration. Where the framework uses
|     `Config::has()` rather than a default, the key is documented as "unset" and MUST stay commented
|     out — writing it changes behaviour even when you write what looks like the default.
|   * Flags gated by #[ConditionalOnProperty] are compared as STRINGS after stringification: only the
|     boolean `true` and the string `'true'` match `havingValue: 'true'` — the integer `1` stringifies to
|     `'1'` and does NOT. Use boolean literals (or env() values, which Laravel already casts).
|   * Duration-shaped values accept either a bare number of seconds or a `Firefly\Resilience\Duration`
|     string: `250ms`, `30s`, `5m`, `1h`.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Component scanning — firefly/autoconfigure, firefly/context
    |--------------------------------------------------------------------------
    |
    | The PSR-4 roots Firefly scans for stereotypes (#[Component]/#[Service]/#[RestController]/
    | #[Controller]/#[Repository]/#[Configuration]) and for every attribute-driven manifest. This is the
    | ONE key an application must get right: `firefly:cache` compiles these roots, and an uncached boot
    | scans them in-process. Leave it empty and the app boots with no routes, handlers, listeners,
    | scheduled tasks, constraints or method-security rules at all.
    |
    */

    'scan' => [
        'paths' => [
            'App\\' => app_path(),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled artifacts — firefly/cli, firefly/context
    |--------------------------------------------------------------------------
    |
    | Where `php artisan firefly:cache` writes the compiled manifests and the #[Transactional] proxies,
    | and where the boot path looks for them. Boot resolves each manifest in this order: the compiled
    | artifact if it exists, else an in-process scan of `scan.paths`, else an empty manifest. So a
    | missing cache directory costs reflection at boot, never correctness.
    |
    | `path` is the directory (read by Firefly\Context\Scan\AppScan and firefly/cli); the two
    | `*_manifest` keys are the two files FireflyAutoConfigureServiceProvider loads directly.
    |
    */

    'cache' => [
        'path' => base_path('bootstrap/cache/firefly'),
        'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
        'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiles — firefly/config
    |--------------------------------------------------------------------------
    |
    | Active profiles for #[Profile] and #[ConditionalOnProfile]. ProfileResolver reads, in order: the
    | FIREFLY_PROFILES_ACTIVE environment variable, then this key, then `app.env`, then the implicit
    | `default` profile — so setting nothing here means "the profile is APP_ENV". A list is accepted and
    | joined with commas.
    |
    | Default: unset (falls back to APP_ENV, then 'default').
    |
    */

    // 'profiles' => [
    //     'active' => ['prod', 'eu'],
    // ],

    /*
    |--------------------------------------------------------------------------
    | Security — firefly/security
    |--------------------------------------------------------------------------
    |
    | OFF by default, and opt-in surface by surface. `enabled` is the master flag: it gates the principal
    | model, the role hierarchy, the user store, the authentication manager, the CQRS authorizers and the
    | programmatic AuthorizationChecker.
    |
    | Each surface below has its own flag. Only `http` ALSO requires the master flag — its filter's
    | constructor needs three master-gated beans, so enabling it alone would bind a filter whose
    | dependencies do not exist. `jwt`, `oauth2.resource_server`, `csrf` and `headers` are independent of
    | the master flag and can be turned on by themselves. Note that authenticating (jwt/oauth2) without
    | `http` or method security enforces no authorization at all — it only establishes a principal.
    |
    */

    'security' => [

        // Master flag. Default: false.
        'enabled' => env('FIREFLY_SECURITY_ENABLED', false),

        /*
         | Method security (#[PreAuthorize], #[PostAuthorize], #[Secured], #[RolesAllowed]).
         |
         | Enforcement treats "no rule recorded for this method" as ALLOW, so an EMPTY method-security
         | manifest silently disables every annotation in the application — it fails OPEN. Boot resolves
         | the manifest from the compiled artifact, else an in-process scan, else empty; `strict` refuses
         | to boot when neither a compiled artifact nor a scan produced one, which is the only defence
         | against a build that ships without the compile step. Turn it on in production images.
         |
         | Default: false.
        */
        'method' => [
            'strict' => env('FIREFLY_SECURITY_METHOD_STRICT', false),
        ],

        /*
         | The shipped in-memory user store, keyed by username. `password` is the ENCODED string —
         | typically `{id}`-prefixed for the DelegatingPasswordEncoder, e.g. `{bcrypt}$2y$...`.
         | `authorities` defaults to [], `enabled` to true, `locked` to false.
         |
         | Default: [] (no users; every login fails with a 401).
        */
        'users' => [
            // 'alice' => [
            //     'password' => '{bcrypt}$2y$12$...',
            //     'authorities' => ['ROLE_ADMIN'],
            //     'enabled' => true,
            //     'locked' => false,
            // ],
        ],

        /*
         | Role implication rules, one per line, in the form `ROLE_A > ROLE_B`. A principal holding
         | ROLE_A is then treated as holding ROLE_B everywhere authority checks run.
         |
         | Default: [] (no implications; roles are compared literally).
        */
        'role_hierarchy' => [
            // 'ROLE_ADMIN > ROLE_USER',
        ],

        /*
         | URL authorization. REQUIRES the master flag above as well as this one. DENY BY DEFAULT: with
         | both on, a request matching NO rule is refused (401 when anonymous, 403 when authenticated).
         | Rules are first-match-wins over Str::is() patterns.
         |
         | `access` is a FIXED vocabulary, not free expression text — HttpSecurity::fromConfig() maps it:
         |
         |     permitAll | denyAll | authenticated | hasRole:<ROLE> | hasAuthority:<AUTHORITY>
         |
         | Anything it does not recognise compiles to denyAll(): the spec is fail-closed, so a typo
         | locks the path down rather than opening it. Write `hasRole:ADMIN`, never `hasRole('ADMIN')`.
         |
         | Defaults: enabled false, rules [].
        */
        'http' => [
            'enabled' => env('FIREFLY_SECURITY_HTTP_ENABLED', false),
            'rules' => [
                // ['pattern' => 'actuator/health', 'access' => 'permitAll'],
                // ['pattern' => 'actuator/*',      'access' => 'hasRole:ACTUATOR'],
                // ['pattern' => '*',               'access' => 'authenticated'],
            ],
        ],

        /*
         | Local JWT bearer authentication. Mutually exclusive with the OAuth2 resource server below —
         | enabling both throws at boot, because the local filter (order -90) would reject tokens before
         | the resource-server filter (order -85) could validate them.
         |
         | `secret` is required once `enabled` is true, and JwtService REFUSES TO BOOT on a placeholder
         | or a secret shorter than its minimum byte length.
         |
         | Defaults: enabled false, algorithm 'HS256', leeway 0, authorities_claim 'authorities'.
        */
        'jwt' => [
            'enabled' => env('FIREFLY_JWT_ENABLED', false),
            'secret' => env('FIREFLY_JWT_SECRET', ''),
            'algorithm' => 'HS256',
            'leeway' => 0,
            'authorities_claim' => 'authorities',
        ],

        /*
         | OAuth2 resource server: validates bearer tokens against a remote JWKS. `jwks_uri` is required
         | once `enabled` is true. An empty `issuer`/`audience` skips that claim check.
         |
         | Defaults: enabled false, issuer '', audience '', authorities_claim 'roles', cache_ttl 3600.
        */
        'oauth2' => [
            'resource_server' => [
                'enabled' => env('FIREFLY_OAUTH2_ENABLED', false),
                'jwks_uri' => env('FIREFLY_OAUTH2_JWKS_URI', ''),
                'issuer' => env('FIREFLY_OAUTH2_ISSUER', ''),
                'audience' => env('FIREFLY_OAUTH2_AUDIENCE', ''),
                'authorities_claim' => 'roles',
                'cache_ttl' => 3600,
            ],
        ],

        /*
         | Response security headers, applied by a filter ordered -95 so they survive on error responses
         | too. Each value below is the framework default and is written verbatim onto the response.
         |
         | Default: enabled false.
        */
        'headers' => [
            'enabled' => env('FIREFLY_SECURITY_HEADERS_ENABLED', false),
            // 'hsts' => 'max-age=31536000; includeSubDomains',
            // 'frame_options' => 'DENY',
            // 'content_type_options' => 'nosniff',
            // 'referrer_policy' => 'no-referrer',
            // 'csp' => "default-src 'self'",
        ],

        /*
         | CSRF protection for state-changing requests. `except` holds Str::is() patterns skipped by the
         | filter — a JSON API authenticated by bearer token usually belongs here.
         |
         | Defaults: enabled false, except [].
        */
        'csrf' => [
            'enabled' => env('FIREFLY_SECURITY_CSRF_ENABLED', false),
            'except' => [
                // 'api/*',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Management endpoints — firefly/actuator
    |--------------------------------------------------------------------------
    |
    | The Spring-Actuator-shaped surface. Mounted at `endpoints.web.base-path`; the shipped endpoint ids
    | are: health, info, env, beans, conditions, mappings, loggers, scheduledtasks — plus metrics and
    | prometheus from firefly/observability.
    |
    */

    'management' => [

        // Master gate: false unmounts every actuator route. Default: true.
        'enabled' => true,

        'endpoints' => [
            'web' => [
                // Default: '/actuator'.
                'base-path' => '/actuator',

                /*
                 | Web exposure, CSV or `*`. `*` is a wildcard in BOTH lists and EXCLUDE WINS, so
                 | `exclude => '*'` is the kill switch. Secure by default: only health and info are
                 | reachable; anything else answers 404 even though the endpoint exists. `env`, `beans`,
                 | `conditions`, `mappings` and `loggers` disclose configuration and wiring — expose them
                 | only behind the security.http rules above.
                 |
                 | Defaults: include 'health,info', exclude ''.
                */
                'exposure' => [
                    'include' => env('FIREFLY_ACTUATOR_EXPOSE', 'health,info'),
                    'exclude' => '',
                ],
            ],
        ],

        'endpoint' => [

            /*
             | Per-endpoint kill switch, checked at dispatch AND on the index: `firefly.management.
             | endpoint.<id>.enabled`. Default for every id: true.
            */
            // 'env' => ['enabled' => false],
            // 'loggers' => ['enabled' => false],

            'health' => [
                /*
                 | 'always' includes each contributor's component details in the body; anything else
                 | (including the default) returns the aggregated status only. Details name drivers,
                 | paths and error messages, so they are off by default.
                 |
                 | Default: 'never'.
                */
                'show-details' => env('FIREFLY_HEALTH_SHOW_DETAILS', 'never'),

                /*
                 | The DB indicator is OPT-IN so a database-less app's /health does not 503. A failing
                 | query is caught and reported DOWN, never surfaced as a 500.
                 |
                 | Default: false.
                */
                'db' => [
                    'enabled' => env('FIREFLY_HEALTH_DB_ENABLED', false),
                ],

                /*
                 | Free-space indicator. Reports DOWN below `threshold` bytes at `path`.
                 |
                 | Defaults: path = the process working directory (getcwd(), NOT base_path() — the sample
                 | below is the value you probably want, not the framework default), threshold = 10485760
                 | (10 MB).
                */
                // 'diskspace' => [
                //     'path' => base_path(),
                //     'threshold' => 10485760,
                // ],

                /*
                 | Probe groups served at /actuator/health/{name} — CSV of indicator names. An UNSET
                 | group is a 404, so these must stay commented out until you mean them.
                 |
                 | Default: unset (no groups configured).
                */
                // 'group' => [
                //     'liveness' => ['include' => 'ping'],
                //     'readiness' => ['include' => 'db,diskSpace'],
                // ],
            ],
        ],

        'info' => [
            /*
             | Surfaced verbatim under the `app` key of /actuator/info.
             |
             | Default: unset (the contributor returns nothing).
            */
            // 'app' => [
            //     'name' => env('APP_NAME', 'LaraFly'),
            //     'version' => '1.0.0',
            // ],

            /*
             | A generated build-info JSON file, surfaced under `build`. A missing file is not an error.
             |
             | Default: firefly-build.json in the process working directory (getcwd(), NOT base_path() —
             | the sample below is the value you probably want, not the framework default).
            */
            // 'build' => [
            //     'path' => base_path('firefly-build.json'),
            // ],

            /*
             | The `runtime` fragment of /actuator/info — PHP version/SAPI/OPcache, Laravel version, LaraFly
             | version, current and peak memory. Gated by #[ConditionalOnProperty(matchIfMissing: true)], so
             | leaving it unset keeps the contributor; setting it false removes the BEAN, not just the output.
             |
             | Default: true.
            */
            // 'runtime' => [
            //     'enabled' => false,
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin dashboard — firefly/admin
    |--------------------------------------------------------------------------
    |
    | A browser dashboard over the actuator's data. It reads the endpoint registry IN-PROCESS, deliberately
    | bypassing the exposure model above — seeing beans, conditions and the environment locally without
    | first publishing them over HTTP to everyone is the whole point.
    |
    | That makes the dashboard's own URL the only boundary, so `enabled` DEFAULTS TO `app.debug`: an app
    | already serving stack traces is a development environment by definition, and an app with debug off
    | must opt in explicitly — and should put the route behind its own auth middleware when it does.
    | Setting the key wins over the debug default in both directions.
    |
    | Defaults: enabled = app.debug, base-path '/firefly', title = app.name, refresh-seconds 10,
    | theme 'auto', graph.max-nodes 220, pages.exclude ''.
    |
    */

    // 'admin' => [
    //     'enabled' => env('FIREFLY_ADMIN_ENABLED', false),
    //     'base-path' => '/firefly',
    //     'title' => env('APP_NAME', 'LaraFly'),
    //
    //     /*
    //      | How often a live page reloads itself. FLOORED AT 2: a shorter interval reloads faster than the
    //      | page renders, so the countdown would never finish and the dashboard would hammer the very
    //      | application it exists to observe.
    //      |
    //      | Default: 10.
    //     */
    //     'refresh-seconds' => 10,
    //
    //     // 'auto' (follow the operating system) | 'light' | 'dark'. Anything unrecognised falls back to
    //     // 'auto' rather than rendering unstyled. Default: 'auto'.
    //     'theme' => 'auto',
    //
    //     'graph' => [
    //         /*
    //          | The node count past which the Bean graph page LISTS the relations instead of drawing them.
    //          | A diagram past a couple of hundred nodes is a hairball rather than something anyone can
    //          | read. Configurable — not a constant — because "unreadable" depends on the screen and the
    //          | application; 0 always lists.
    //          |
    //          | Default: 220.
    //         */
    //         'max-nodes' => 220,
    //     ],
    //
    //     'pages' => [
    //         /*
    //          | CSV of page slugs to REFUSE. This is a refusal, not a menu preference: an excluded page is
    //          | hidden from the menu AND its URL 404s — hiding `env` from the menu achieves nothing if the
    //          | URL still answers. The index page's slug is `overview`.
    //          |
    //          | Default: '' (nothing excluded).
    //         */
    //         'exclude' => 'env,configprops',
    //     ],
    //
    //     /*
    //      | THE DATA BROWSER — /firefly/data
    //      |
    //      | A browsable, searchable, sortable view of the records behind your repositories, in the shape
    //      | Django's admin made familiar. It discovers every bean implementing CrudRepository — which you
    //      | get from `extends EloquentRepository` — and reads THROUGH the repository, so what it shows is
    //      | what your own data layer returns, not a raw table dump. Nothing to register: the sample
    //      | App\Orders\OrderRepository shows up as "Order" the moment this is switched on.
    //      |
    //      | IT HAS ITS OWN SWITCH, DEFAULTING TO FALSE, even though the dashboard around it already
    //      | defaults to app.debug. Beans, conditions and mappings describe the SHAPE of an application;
    //      | these are its customers' records. "Debug is on" is a fine reason to show the first and not the
    //      | second, so the browser is off until someone says otherwise — and when it is off, its pages
    //      | 404 rather than 403, because a 403 confirms the surface exists.
    //     */
    //     'data' => [
    //         // Default: false.
    //         'enabled' => env('FIREFLY_ADMIN_DATA_ENABLED', false),
    //
    //         /*
    //          | Whether the browser may EDIT and DELETE records. Ineffective on its own — a write needs
    //          | this AND `enabled` — so switching the browser on never silently makes it writable. With
    //          | this off the edit form is not rendered and the write URLs refuse.
    //          |
    //          | There is deliberately no "create": a generic form cannot honour the constructor
    //          | invariants of an arbitrary entity, and one that quietly bypassed them would be worse than
    //          | not having it. Create records through your own use cases.
    //          |
    //          | Default: false.
    //         */
    //         'writable' => env('FIREFLY_ADMIN_DATA_WRITABLE', false),
    //
    //         // Rows per page, and the ceiling a `?per-page=` in the URL may raise it to. Both are clamped
    //         // to a hard maximum of 1000 so no query string can ask for the whole table at once.
    //         // Defaults: 25 and 200.
    //         'page-size' => 25,
    //         'max-page-size' => 200,
    //
    //         /*
    //          | CSV of resource slugs to REFUSE — same hard refusal as `pages.exclude` above: excluded
    //          | resources are absent from the menu AND their URLs 404. Use it for the tables you do not
    //          | want browsable even by someone who is allowed in at all.
    //          |
    //          | Default: '' (nothing excluded).
    //         */
    //         'exclude' => 'order',
    //     ],
    // ],

    /*
    |--------------------------------------------------------------------------
    | API documentation — firefly/openapi
    |--------------------------------------------------------------------------
    |
    | The OpenAPI 3.1 document is generated from the same compiled artifacts the dispatcher and the
    | validator read — RouteManifest for paths/operations/parameters, ConstraintManifest for request-body
    | schemas — so there is no annotation dialect and nothing that can drift. `php artisan firefly:openapi`
    | writes the same document to a file or to stdout.
    |
    | Both routes are mounted natively on the illuminate Router from a BootPass, which is what makes their
    | paths configurable at all: an attribute route bakes its literal into a compiled RouteDescriptor. It is
    | also why this package's own routes never appear in the document it generates.
    |
    | SECURING IT. The whole surface is ordinary routes, so `firefly.security.http.rules` above covers it
    | with no code edge. A deployment that wants no documentation surface in production sets `enabled` to
    | false — which leaves both paths genuinely unrouted, not merely blank — and generates the document in
    | CI with `firefly:openapi --output=` instead.
    |
    | Defaults: enabled true, path '/openapi.json', viewer.enabled true, viewer.path '/openapi',
    | viewer.style 'swagger', title 'API', version '0.0.0', description '', servers [], exclude '',
    | include-html false.
    |
    */

    // 'openapi' => [
    //     'enabled' => true,
    //     'path' => '/openapi.json',
    //
    //     'viewer' => [
    //         'enabled' => true,
    //         'path' => '/openapi',
    //
    //         /*
    //          | Which console /openapi renders. Three values, and only one of them makes a third-party
    //          | request:
    //          |
    //          |   'swagger' — the DEFAULT. The official Swagger UI, served from THIS application's own
    //          |               origin out of the swagger-api/swagger-ui composer package (a hard dependency
    //          |               of firefly/openapi, so it is already on disk). Byte-for-byte the distribution
    //          |               Swagger publishes — try-it-out, deep linking, OAuth2 — with no CDN request and
    //          |               no npm step. Falls back to 'builtin' if the dist is somehow missing, rather
    //          |               than rendering a page whose assets 404.
    //          |   'builtin' — a hand-written, dependency-free reference: one inline script, no third-party
    //          |               JavaScript at all. Groups operations by tag and resolves $ref client-side.
    //          |   'cdn'     — Swagger UI fetched from cdn.jsdelivr.net at an exactly pinned version. The
    //          |               ONLY style that makes a network request at page view, and therefore the only
    //          |               one that renders nothing in an air-gapped or strict-CSP deployment. No
    //          |               Subresource Integrity hash is claimed: one the framework cannot verify at
    //          |               release time would be security theatre.
    //          |
    //          | Anything unrecognised falls back to 'swagger' rather than rendering a blank page.
    //          |
    //          | Default: 'swagger'.
    //         */
    //         'style' => 'swagger',
    //
    //         /*
    //          | The older spelling of `style => 'cdn'`, kept so an application that set it before `style`
    //          | existed keeps the behaviour it configured. `cdn => true` still FORCES the CDN page and wins
    //          | over `style`; prefer `style` in new configuration.
    //          |
    //          | Default: false.
    //         */
    //         // 'cdn' => false,
    //     ],
    //
    //     // Info Object members, written verbatim into the document.
    //     'title' => env('APP_NAME', 'API'),
    //     'version' => '1.0.0',
    //     'description' => '',
    //
    //     /*
    //      | Server Objects. Both spellings a real config file uses are accepted — a bare URL string, and
    //      | OpenAPI's own object form with a `description`. An entry that is neither is DROPPED rather than
    //      | emitted, because a Server Object with no `url` is invalid under the 3.1 schema.
    //      |
    //      | Default: [].
    //     */
    //     'servers' => [
    //         'https://api.example.test',
    //         // ['url' => 'https://staging.example.test', 'description' => 'Staging'],
    //     ],
    //
    //     /*
    //      | CSV of path prefixes left out of the document. Note this only removes them from the SPEC — it
    //      | does not unroute them; that is what firefly.security.http.rules is for.
    //      |
    //      | Default: ''.
    //     */
    //     'exclude' => '/internal,/admin',
    //
    //     /*
    //      | Document #[Controller] HTML routes as `text/html` operations. Off by default: an HTML page is
    //      | not part of a JSON API's contract, and a typed client generated from a document containing one
    //      | gets a method that returns markup.
    //      |
    //      | Default: false.
    //     */
    //     'include-html' => false,
    // ],

    /*
    |--------------------------------------------------------------------------
    | Observability — firefly/observability
    |--------------------------------------------------------------------------
    |
    | The Micrometer analogue. `metrics.enabled` gates the MeterRegistry, the HTTP MetricsFilter, the
    | CQRS metrics recorder and both /actuator/metrics and /actuator/prometheus, with the same key on
    | each so they can never disagree.
    |
    */

    'observability' => [
        'metrics' => [

            // Default: true (matchIfMissing).
            'enabled' => true,

            /*
             | Naming a CACHE STORE swaps SimpleMeterRegistry for CacheMeterRegistry, whose counters and
             | timers accumulate ACROSS PROCESSES. This matters under PHP-FPM: each request is a fresh
             | process, so with the in-memory registry a scrape of /actuator/metrics sees only what that
             | scrape's own request recorded — which reads as data but is not. Point it at a store with
             | an atomic increment (redis, memcached, apc, dynamodb); `array` is no better than memory.
             |
             | Opt-in on purpose: a registry that silently starts writing to whatever cache an app
             | happens to have configured is a surprise.
             |
             | Default: '' (in-process SimpleMeterRegistry).
            */
            'store' => env('FIREFLY_METRICS_STORE', ''),

            /*
             | Expiry in seconds for each cache-backed meter, so a meter nothing writes any more is
             | eventually reclaimed instead of living in the store forever. Only consulted when `store`
             | is set; 0 or less means no expiry.
             |
             | Default: 0 (no expiry).
            */
            'ttl' => (int) env('FIREFLY_METRICS_TTL', 0),
        ],

        /*
         | The rolling buffer behind /actuator/httpexchanges (and the request counter in /actuator/process)
         | — the last N requests this application answered, newest first.
         |
         | A SEPARATE SWITCH FROM METRICS, deliberately: metrics aggregate, this retains individual
         | requests. An operator happy to publish latency histograms may still want no per-request record
         | kept anywhere, and has to be able to say so without losing metrics.
        */
        'httpexchanges' => [

            /*
             | Gates the RECORDING FILTER, not the endpoints — /actuator/httpexchanges and /actuator/process
             | stay mounted either way and answer `"recording": false`, because two 404s that explain
             | nothing is the opposite of what an operator staring at an empty panel needs.
             |
             | Compared as a string by #[ConditionalOnProperty], so `1`/`'on'`/`'yes'` read as OFF. Use a
             | boolean literal.
             |
             | Default: true (matchIfMissing).
            */
            'enabled' => true,

            /*
             | Ring size, clamped to [1, 10000]. Both ends of the clamp are load-bearing: 0 would divide by
             | zero inside the cache-backed recorder (a config typo that 500s every request), and capacity
             | is the number of cache keys fetched per endpoint call, so a very large value builds an
             | endpoint that times out.
             |
             | Default: 100.
            */
            'capacity' => 100,

            /*
             | Naming a CACHE STORE swaps InMemoryHttpExchangeRecorder for CacheHttpExchangeRecorder. This
             | matters more here than it does for metrics: under PHP-FPM the in-memory ring is not merely
             | stale but always EMPTY — each request is a fresh process, and the request rendering the
             | endpoint has not been recorded yet, because the filter records on the way out.
             |
             | Default: '' (process-local InMemoryHttpExchangeRecorder).
            */
            'store' => env('FIREFLY_HTTPEXCHANGES_STORE', ''),

            /*
             | Expiry in seconds for each cache-backed row. Only consulted when `store` is set; 0 or less
             | means no expiry.
             |
             | Default: 0 (no expiry).
            */
            'ttl' => (int) env('FIREFLY_HTTPEXCHANGES_TTL', 0),

            /*
             | Add masked request headers to each row. Request and response BODIES are never recorded, with
             | or without this.
             |
             | Default: false.
            */
            'include-headers' => false,

            /*
             | Glob patterns whose requests are recorded by nobody. Setting this REPLACES the default rather
             | than adding to it, and an empty list means "record everything, management traffic included".
             |
             | The default is the management base path and everything under it, because a dashboard is a
             | polling client: left in, a panel refreshing /actuator/httpexchanges would evict every genuine
             | request from a 100-row ring and then show the operator nothing but their own polling.
             | Running firefly/admin? Add its base path here for exactly the same reason — the framework
             | does not reach into another package's key to guess at its mount point.
             |
             | Default: the value of management.endpoints.web.base-path, plus that path with `/*`.
            */
            // 'exclude' => ['actuator', 'actuator/*', 'firefly', 'firefly/*'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resilience — firefly/resilience
    |--------------------------------------------------------------------------
    |
    | Named pattern instances, read once into ResilienceRegistry. `<pattern>.<name>.<key>` where pattern
    | is retry / circuit-breaker / rate-limiter / bulkhead / time-limiter and name is whatever your code
    | passes to $registry->circuitBreaker('payments'). An unconfigured name still works — every pattern
    | has defaults — as long as no OTHER name is configured under that pattern.
    |
    | NOTE — this section is the one exception to the "commented out at its default" convention above. A
    | pattern instance is named by YOUR code, so there is no default instance to print; the commented blocks
    | below are ILLUSTRATIVE named instances, and several of their values are deliberately not the framework
    | default (the real defaults are: retry wait-duration 0, backoff-multiplier 1.0; circuit-breaker
    | minimum-number-of-calls 0; time-limiter timeout 30s). Only `store.lock-block-timeout` below is written
    | at its true default.
    |
    | See docs/modules/resilience.md for the full key-by-key tables.
    |
    */

    'resilience' => [

        /*
         | How long a pattern waits for the shared-state mutex before failing fast with a 503. This is
         | the WAIT budget, not how long the lock is held. The right value depends on the cache driver:
         | an array store or a local Redis hands over in microseconds, a database-backed cache across an
         | availability zone can legitimately need tens of milliseconds.
         |
         | Default: 0.5 (500ms).
        */
        'store' => [
            'lock-block-timeout' => '500ms',
        ],

        // 'retry' => [
        //     'payments' => ['max-attempts' => 3, 'wait-duration' => '250ms', 'backoff-multiplier' => 2.0],
        // ],
        // 'circuit-breaker' => [
        //     'payments' => [
        //         'failure-threshold' => 5,
        //         'window-size' => 10,
        //         'minimum-number-of-calls' => 5,
        //         'wait-duration-in-open' => '30s',
        //         'half-open-max-calls' => 1,
        //         'half-open-probe-timeout' => '30s',
        //     ],
        // ],
        // 'rate-limiter' => [
        //     'api' => ['max-tokens' => 10, 'refill-rate' => 10.0, 'timeout' => 0],
        // ],
        // 'bulkhead' => [
        //     'db' => ['max-concurrent' => 10, 'max-wait' => 0, 'permit-ttl' => '60s'],
        // ],
        // 'time-limiter' => [
        //     'payments' => ['timeout' => '2s'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling — firefly/scheduling
    |--------------------------------------------------------------------------
    |
    | Which DistributedLock backs #[Scheduled] tasks so one task runs once across N instances:
    |   'none'     — no coordination (correct for a single instance). The default.
    |   'cache'    — the app's atomic cache lock.
    |   'postgres' — Postgres advisory locks; requires firefly/scheduling-postgres.
    |
    */

    'scheduling' => [
        'lock' => [
            'provider' => env('FIREFLY_SCHEDULING_LOCK', 'none'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CQRS — firefly/cqrs
    |--------------------------------------------------------------------------
    */

    'cqrs' => [

        /*
         | The broker destination domain events are published to when a handler's #[CommandHandler] does
         | not name one of its own.
         |
         | Default: 'cqrs.events'.
        */
        'default_destination' => 'cqrs.events',

        /*
         | What DomainEventBridge does when publishing throws. The publish runs AFTER the DB commit, so
         | the write already succeeded:
         |   'log'   — swallow and log; the command result stands, the integration publish is best-effort.
         |   'raise' — rethrow wrapped in CommandProcessingException so the caller sees the failure.
         |
         | Default: 'log'.
        */
        'event_failure_strategy' => 'log',

        /*
         | Default TTL in seconds for #[Cacheable] query results that do not declare their own. UNSET
         | means "no default TTL" — not zero — so leave it commented out unless you want one.
         |
         | Default: unset.
        */
        // 'query' => [
        //     'cache_ttl' => 60,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events — firefly/eda (+ eda-rabbitmq / eda-postgres / eda-kafka)
    |--------------------------------------------------------------------------
    |
    | `provider` selects the EventPublisher adapter:
    |   'memory'   — in-process bus. The default; listeners run synchronously.
    |   'queue'    — Laravel queue; listeners run in `queue:work`.
    |   'rabbitmq' — requires firefly/eda-rabbitmq.
    |   'postgres' — requires firefly/eda-postgres; enables the same-transaction outbox.
    |   'kafka'    — requires firefly/eda-kafka and ext-rdkafka.
    |
    | The broker providers are consumed by `php artisan firefly:eda:consume`; 'queue' uses
    | `php artisan queue:work`; 'memory' has no consumer loop.
    |
    */

    'eda' => [

        'provider' => env('FIREFLY_EDA_PROVIDER', 'memory'),

        /*
         | Envelope encoding. Only 'json' ships today; anything else throws at boot rather than picking
         | a format silently.
         |
         | Default: 'json'.
        */
        'serialization_format' => 'json',

        /*
         | In-process delivery retries per listener before the envelope goes to the DeadLetterStore, and
         | the delay between attempts in seconds.
         |
         | Defaults: retries 0, retry_delay 0.0.
        */
        'retries' => 0,
        'retry_delay' => 0.0,

        /*
         | Broker destinations `firefly:eda:consume` binds when `--destination` is not passed. Must be a
         | list of strings.
         |
         | Default: [].
        */
        'destinations' => [
            // 'cqrs.events',
        ],

        /*
         | provider=queue: which Laravel queue connection and queue name carry the envelopes. Both UNSET
         | means "the application's own defaults".
         |
         | Default: unset.
        */
        // 'queue' => [
        //     'connection' => 'redis',
        //     'name' => 'events',
        // ],

        /*
         | Consumer group identity, used by the Kafka adapter.
         |
         | Default: 'firefly'.
        */
        // 'consumer' => [
        //     'group_id' => 'firefly',
        // ],

        /*
         | provider=rabbitmq. The exchange is topic-routed by event type; the DLX receives envelopes a
         | consumer nacks.
         |
         | Defaults: host '127.0.0.1', port 5672, user 'guest', password 'guest', vhost '/',
         | exchange 'firefly.events', queue 'firefly.eda', dlx 'firefly.events.dlx', prefetch 10.
        */
        // 'rabbitmq' => [
        //     'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        //     'port' => (int) env('RABBITMQ_PORT', 5672),
        //     'user' => env('RABBITMQ_USER', 'guest'),
        //     'password' => env('RABBITMQ_PASSWORD', 'guest'),
        //     'vhost' => env('RABBITMQ_VHOST', '/'),
        //     'exchange' => 'firefly.events',
        //     'queue' => 'firefly.eda',
        //     'dlx' => 'firefly.events.dlx',
        //     'prefetch' => 10,
        // ],

        /*
         | provider=postgres — the same-transaction outbox. `connection` UNSET means the default database
         | connection. `channel` is the LISTEN/NOTIFY channel; `max_attempts` bounds relay retries before
         | a row is marked FAILED.
         |
         | `relay.downstream_provider` is OPTIONAL and only used by `php artisan firefly:outbox:relay`,
         | which forwards committed outbox rows to a SECOND broker. It takes 'rabbitmq', 'kafka', an
         | EventPublisher class-string, or the id of your own binding. Leave it unset unless you run the
         | relay: provider=postgres already delivers rows in-process via `firefly:eda:consume`.
         |
         | Defaults: connection unset, channel 'firefly_eda_events', max_attempts 3,
         | relay.downstream_provider unset.
        */
        // 'postgres' => [
        //     'connection' => 'pgsql',
        //     'channel' => 'firefly_eda_events',
        //     'max_attempts' => 3,
        //     'relay' => [
        //         'downstream_provider' => 'rabbitmq',
        //     ],
        // ],

        /*
         | provider=kafka. Comma-separated broker list.
         |
         | Default: '127.0.0.1:9092'.
        */
        // 'kafka' => [
        //     'brokers' => env('KAFKA_BROKERS', '127.0.0.1:9092'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Messaging — firefly/messaging
    |--------------------------------------------------------------------------
    |
    | The point-to-point #[MessageListener] transport, independent of the event bus above.
    |   'memory' — in-process. The default.
    |   'queue'  — Laravel queue; both keys UNSET mean the application's own defaults.
    |
    */

    'messaging' => [
        'provider' => env('FIREFLY_MESSAGING_PROVIDER', 'memory'),

        // 'queue' => [
        //     'connection' => 'redis',
        //     'name' => 'messages',
        // ],
    ],

];
