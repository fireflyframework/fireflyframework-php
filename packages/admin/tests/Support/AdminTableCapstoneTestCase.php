<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Admin\Tests\Support\Fixtures\BillingProperties;
use Firefly\Admin\Tests\Support\Fixtures\HttpExchangesEndpointStub;
use Firefly\Admin\Tests\Support\Fixtures\LedgerProperties;
use Firefly\Admin\Tests\Support\Fixtures\MetricsEndpointStub;
use Firefly\Admin\Tests\Support\Fixtures\OAuth2ClientsEndpointStub;
use Firefly\Config\Scanner\ConfigPropertiesDescriptor;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionOutcome;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Foundation\Application;

/**
 * The dashboard capstone, given a route table worth listing.
 *
 * AdminCapstoneTestCase boots no application controllers, so its RouteManifest is EMPTY and
 * `/firefly/mappings` renders the "No routes mapped" empty state — which proves the empty state and
 * nothing else. A listing that pages, sorts and searches has to be asserted against rows, so this subclass
 * binds the manifest itself: the same seam WebServiceProvider binds when a scan or a compiled artifact
 * produced one, so MappingsEndpoint, AdminAction, the view and the router all see exactly what they would
 * see in an application. Bound in defineFireflyEnvironment, which Testbench runs while the application is
 * being created — before WebServiceProvider::register() asks `bound()` — so this instance wins rather than
 * racing the scanner.
 *
 * The rows MIRROR THE SKELETON'S OWN CONTROLLERS, because tests/Browser/AdminTablesTest.php drives the
 * shipped skeleton in Chromium and asserts the same facts in pixels: `/greetings/{name}` (the path that
 * rendered as six stacked lines), `DELETE /orders/{id}` (the verb that clipped), and a handful of orders
 * routes for `?q=orders` to narrow to. Two assertions about one application beat two applications.
 *
 * THE ROWS ARE A HOOK, not a literal in defineFireflyEnvironment, because seven routes cannot page: the
 * smallest size the rows-per-page control offers by default is 25, so `lastPage()` is 1 and the pager's
 * whole paged branch — the window, Previous/Next, the first/last jumps — never renders. A subclass that
 * needs more than one page overrides `routes()` and appends; see AdminTablePagedCapstoneTestCase.
 *
 * ROUTES ARE NOT THE ONLY LISTING THIS HARNESS HAS TO FEED. Three of the wiring pages are listings too, and
 * two of them are EMPTY in a bare capstone: the scheduled manifest the parent stubs holds no tasks, and the
 * condition report an auto-configured testbench produces has matches but no NON-matches — nothing backs off
 * when nothing was overridden. An empty listing renders its empty state, which is a branch that proves the
 * empty state and nothing about a colgroup, a sort link or a pager, so this case seeds both:
 *
 * - the tasks go in through `defineFireflyEnvironment`, because ScheduledTasksEndpoint is a singleton that
 *   takes the manifest in its CONSTRUCTOR and is resolved once during the boot passes — a manifest rebound
 *   from a test body arrives after the endpoint has already captured the empty one;
 * - the non-matches go in after boot, because ConditionEvaluationReport is bound by ActuatorRouteRegistrar
 *   from the BootContext and is MUTABLE: the endpoint holds the same instance, so recording an outcome on
 *   it is visible to the next request. There is no earlier seam — the report does not exist until boot.
 *
 * THE CONFIG PROPERTIES ARE THE FOURTH SEEDED LISTING, and it is empty in a bare capstone for a reason
 * worth spelling out: ConfigPropsEndpoint resolves its own manifest, from a compiled artifact or from a
 * scan of `firefly.scan.paths`, and a testbench application configures neither — so `/firefly/configprops`
 * renders two empty states and the page's TWO listings, which are independently qualified and carry each
 * other's position, are asserted by nothing at all. The endpoint documents the seam this uses: a
 * container-bound ConfigPropertiesManifest wins over both, "so a future pass that binds it (or a test that
 * supplies one) is honoured rather than ignored". The BOUND DTO is then bound as an instance, because
 * `describe()` reads the resolved object back out of the container — that IS the endpoint's contract — and
 * the profile-gated one is deliberately left unbound, which is the state ConfigRegistrar leaves it in.
 *
 * AND THE THREE RUNTIME LISTINGS ARE SEEDED BY REGISTERING THEIR ENDPOINTS, because a testbench that boots
 * no observability stack and no authorization server does not have them at all: `/firefly/metrics`,
 * `/firefly/http` and `/firefly/oauth2` answer 404 through AdminPage::requires rather than rendering an
 * empty listing. The stubs go into the REAL ActuatorRegistry after boot — the same seam
 * AdminOAuth2PageProcessLocalTest uses — so the reader resolves them, AdminAction reads them and the views
 * render exactly as they would over the shipped endpoints; what is stubbed is the meter registry, the
 * exchange ring and the client store behind them, none of which a single-process test can fill honestly.
 */
abstract class AdminTableCapstoneTestCase extends AdminCapstoneTestCase
{
    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $app->instance(RouteManifest::class, new RouteManifest($this->routes()));
        $app->instance(ScheduledManifest::class, new ScheduledManifest($this->tasks()));
        $app->instance(ConfigPropertiesManifest::class, new ConfigPropertiesManifest($this->configProperties()));

        foreach ($this->boundConfigProperties() as $class => $instance) {
            $app->instance($class, $instance);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $report = $this->app()->make(ConditionEvaluationReport::class);
        foreach ($this->backedOff() as [$class, $attribute, $reason]) {
            $report->record($class, $attribute, ConditionOutcome::noMatch($reason));
        }

        $registry = $this->app()->make(ActuatorRegistry::class);
        $registry->register(new MetricsEndpointStub($this->meters()));
        $registry->register(new HttpExchangesEndpointStub($this->exchanges()));
        $registry->register(new OAuth2ClientsEndpointStub($this->clients()));
    }

    /**
     * @return list<RouteDescriptor>
     */
    protected function routes(): array
    {
        return [
            new RouteDescriptor('GET', '/greetings/{name}', 'App\Http\GreetingController', 'show', 200, 'greetings.show', []),
            new RouteDescriptor('GET', '/', 'App\Http\WelcomeController', 'index', 200, 'welcome', [], html: true),
            new RouteDescriptor('GET', '/orders', 'App\Http\OrderController', 'index', 200, 'orders.index', []),
            new RouteDescriptor('GET', '/orders/{id}', 'App\Http\OrderController', 'show', 200, 'orders.show', []),
            new RouteDescriptor('POST', '/orders', 'App\Http\OrderController', 'store', 201, 'orders.store', []),
            new RouteDescriptor('PUT', '/orders/{id}', 'App\Http\OrderController', 'update', 200, 'orders.update', []),
            new RouteDescriptor('DELETE', '/orders/{id}', 'App\Http\OrderController', 'destroy', 204, 'orders.destroy', []),
        ];
    }

    /**
     * Two #[Scheduled] methods, one on each trigger the page has a column for.
     *
     * `fixedRate` IS A DURATION STRING, not a number of milliseconds — ScheduledDescriptor types all three
     * triggers as `?string` and Cadence parses the intervals through Duration::parse — so `30s` is what the
     * endpoint publishes and what the Fixed rate column has to render. One task carries a cron expression
     * and no interval, the other an interval and no cron, which is also the pair that proves the em-dash:
     * every row has exactly one trigger and three empty cells beside it.
     *
     * @return list<ScheduledDescriptor>
     */
    protected function tasks(): array
    {
        return [
            new ScheduledDescriptor('App\Jobs\NightlyReconciliation', 'run', cron: '0 2 * * *', zone: 'UTC'),
            new ScheduledDescriptor('App\Jobs\HeartbeatProbe', 'ping', fixedRate: '30s'),
        ];
    }

    /**
     * The #[ConfigProperties] DTOs this application declares — one bound, one gated off by a profile.
     *
     * Two descriptors rather than one, because the Config properties page shows TWO listings and the second
     * one only renders when something failed to bind. The bound DTO carries three properties, so the bound
     * listing has three rows over two classes' worth of columns and "one row per property, not per DTO" is
     * an observable claim rather than a docblock.
     *
     * @return list<ConfigPropertiesDescriptor>
     */
    protected function configProperties(): array
    {
        return [
            new ConfigPropertiesDescriptor(BillingProperties::class, 'billing'),
            new ConfigPropertiesDescriptor(LedgerProperties::class, 'ledger', ['production']),
        ];
    }

    /**
     * The DTOs of `configProperties()` that are actually IN the container, and their instances.
     *
     * A pair rather than a list, because that is what ConfigPropsEndpoint::describe() reads: it asks the
     * container for the class and reports `bound: false` when nothing answers. A subclass that adds a bound
     * DTO has to add it in BOTH places — the manifest says the application declares it, this says the
     * application resolved it — and the difference between the two is the Not-bound panel.
     *
     * @return array<class-string, object>
     */
    protected function boundConfigProperties(): array
    {
        return [BillingProperties::class => new BillingProperties];
    }

    /**
     * The meters the Metrics page lists, as the registry would report them.
     *
     * FOUR NAMES AND SIX MEASUREMENTS, because the listing unit on that page is the METER and not the
     * measurement: `http.server.requests` carries a count and a total time that are two readings of one
     * thing, and a page that sliced by measurement would put the count on one page and the total it counts
     * on the next. Four meters over six rows is the smallest fixture in which those two numbers can be
     * observed to travel together.
     *
     * `php.memory.used.bytes` and `http.server.requests.duration` are here for their SUFFIXES: Format
     * infers the unit from the meter name, so those two render as `2.0 MB` and a duration while the plain
     * counters render as counts — the Value column is not one alphabet, which is exactly why the Relative
     * bar beside it is the only comparison the page offers. `firefly.mail.sent` has no measurements at all,
     * which is legal (the endpoint answers per NAME and several meters may share one) and is the view's
     * `@empty` arm.
     *
     * @return array<string, list<array{statistic: string, value: float}>>
     */
    protected function meters(): array
    {
        return [
            'http.server.requests' => [
                ['statistic' => 'COUNT', 'value' => 128.0],
                ['statistic' => 'TOTAL_TIME', 'value' => 3.5],
            ],
            'http.server.requests.duration' => [['statistic' => 'TOTAL_TIME', 'value' => 0.042]],
            'firefly.cache.hits' => [['statistic' => 'COUNT', 'value' => 512.0]],
            'php.memory.used.bytes' => [['statistic' => 'VALUE', 'value' => 2097152.0]],
            'firefly.mail.sent' => [],
        ];
    }

    /**
     * The ring the HTTP traffic page lists, newest first and in the endpoint's own row shape.
     *
     * The ages are RELATIVE to the moment the case boots, because the When column renders through
     * Format::since: a fixed instant would drift past its 24-hour boundary and start rendering as an ISO
     * date instead of an age, which is a fixture that changes its own meaning with the calendar. The four
     * rows cover the three status bands the view colours (`ok`, `warn`, `err`) and give the Method column
     * something to order by other than its own default.
     *
     * @return list<array<string, mixed>>
     */
    protected function exchanges(): array
    {
        $now = microtime(true);
        $at = static fn (float $secondsAgo): string => gmdate('Y-m-d\TH:i:s', (int) ($now - $secondsAgo)).'.000000Z';

        return [
            ['timestamp' => $at(2), 'method' => 'GET', 'uri' => '/orders/{id}', 'status' => 200, 'durationMs' => 12.4,
                'correlationId' => '0a9f4c1e-7c1c-4d0b-9f3a-2b6d5e8a1c77', 'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736'],
            ['timestamp' => $at(45), 'method' => 'DELETE', 'uri' => '/orders/{id}', 'status' => 204, 'durationMs' => 8.1,
                'correlationId' => '1b8e3d2f-6a5b-4c9d-8e7f-3c4d5e6f7a88', 'traceId' => '5cf03f4688c45eb7b4df03ae1f1f5847'],
            ['timestamp' => $at(300), 'method' => 'GET', 'uri' => '/greetings/{name}', 'status' => 404, 'durationMs' => 3.2,
                'correlationId' => '2c7d4e3a-5b6c-4d8e-9f0a-4d5e6f7a8b99', 'traceId' => '6df14f5799d56fc8c5ef14bf2f2f6958'],
            ['timestamp' => $at(7200), 'method' => 'POST', 'uri' => '/orders', 'status' => 500, 'durationMs' => 91.7,
                'correlationId' => '3d6e5f4b-4c7d-4e9f-8a1b-5e6f7a8b9c00', 'traceId' => '7ef25f68aae67fd9d6ff25cf3f3f7a69'],
        ];
    }

    /**
     * The registered OAuth2 clients, in OAuth2ClientsEndpoint's row shape.
     *
     * Three of them, one per authentication method the page has ever had to draw, and one — `storefront` —
     * with a zero active count, which on a process-local store is the cell the page renders as `—` rather
     * than as the fact it is not. Both switches are published on every row (`requireProofKey`, what the
     * client registered, and `requiresProofKey`, what the endpoints enforce) so that a page rendering the
     * wrong one is visible here rather than only in AdminOAuth2PageSingleReadTest.
     *
     * @return list<array<string, mixed>>
     */
    protected function clients(): array
    {
        return [
            $this->client('storefront', 'Storefront', ['client_secret_basic'], ['authorization_code', 'refresh_token'], 0),
            $this->client('reporting', 'Reporting exports', ['client_secret_post'], ['client_credentials'], 2),
            $this->client('mobile-app', 'Mobile application', ['none'], ['authorization_code'], 7),
        ];
    }

    /**
     * @param  list<string>  $authentication
     * @param  list<string>  $grants
     * @return array<string, mixed>
     */
    private function client(string $clientId, string $clientName, array $authentication, array $grants, int $active): array
    {
        return [
            'id' => $clientId,
            'clientId' => $clientId,
            'clientName' => $clientName,
            'authenticationMethods' => $authentication,
            'grantTypes' => $grants,
            'scopes' => ['openid', 'profile'],
            'redirectUris' => ['https://'.$clientId.'.test/callback'],
            'postLogoutRedirectUris' => [],
            'requireProofKey' => false,
            'requiresProofKey' => true,
            'requireAuthorizationConsent' => $authentication === ['none'],
            'accessTokenFormat' => 'self_contained',
            'accessTokenTtl' => 300,
            'activeAuthorizations' => $active,
        ];
    }

    /**
     * Conditions that did NOT match, so the Conditions page has two panels worth listing rather than one.
     *
     * The shape mirrors what auto-configuration records for real: a class, the attribute FQCN that judged
     * it, and the reason naming the value observed. Two of them, because one row cannot show that the
     * Backed-off panel sorts and pages on its OWN qualifier rather than on its neighbour's.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    protected function backedOff(): array
    {
        return [
            [
                'App\Cache\RedisCacheAutoConfiguration',
                'Firefly\Context\Condition\Attributes\ConditionalOnMissingBean',
                'a CacheManager bean is already defined by the application',
            ],
            [
                'App\Mail\SmtpMailerAutoConfiguration',
                'Firefly\Context\Condition\Attributes\ConditionalOnProperty',
                '(firefly.mail.enabled=false) did not match required value \'true\'',
            ],
        ];
    }
}
