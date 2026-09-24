<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Admin\Tests\Support\Fixtures\BillingProperties;
use Firefly\Admin\Tests\Support\Fixtures\LedgerProperties;
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
