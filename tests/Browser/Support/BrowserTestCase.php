<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Admin\Settings\SettingsConsole;
use Firefly\Admin\Settings\SettingsSettings;
use Firefly\Cli\Tests\Support\SkeletonApp;
use Firefly\Cli\Tests\Support\SkeletonExampleTestCase;
use Firefly\Config\Config;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LogicException;
use RuntimeException;

/**
 * The application every browser scenario drives: the shipped skeleton, compiled by the real firefly:cache
 * writer and booted under Testbench with the provider set a created app gets — served to Chromium by the
 * plugin's in-process server, so the test keeps the container and the SQLite connection.
 *
 * Every surface a person would look at is switched ON here by its own key. Two of those keys default to
 * `app.debug` in the framework (`firefly.web.error-page.trace`, `firefly.admin.enabled`), and the plugin
 * forces `app.debug=false` while it handles each browser request — so relying on the default would render
 * the production page in a suite that means to test the debug one. Subclasses flip `trace()` and add
 * `securityOverrides()`; both are read before boot, which is why they are hooks and not per-test calls.
 */
abstract class BrowserTestCase extends SkeletonExampleTestCase
{
    use SeedsOrders;

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return DiscoveredProviders::forSkeleton();
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'app.name' => 'LaraFly',
            'app.env' => $this->environment(),
            'firefly.web.error-page.trace' => $this->trace(),
            'firefly.web.problem.disclose' => false,
            'firefly.admin.enabled' => true,
            'firefly.admin.data.enabled' => true,
            'firefly.admin.data.writable' => true,
            'firefly.admin.data.relations' => true,
            'firefly.admin.settings.enabled' => true,
            'firefly.admin.settings.writable' => true,
            'firefly.admin.datasource.wizard' => true,
            'firefly.management.enabled' => true,
            'firefly.management.endpoint.health.db.enabled' => true,
            'firefly.openapi.enabled' => true,
            'firefly.openapi.viewer.enabled' => true,
            ...$this->securityOverrides(),
        ];
    }

    /** Whether the error page shows the exception and its trace (the debug page) or only status + code. */
    protected function trace(): bool
    {
        return true;
    }

    /**
     * `app.env`. The error page's hint footer (the one that names APP_DEBUG and the trace key) follows the
     * environment, not the trace key, so the production variant switches this too.
     */
    protected function environment(): string
    {
        return 'local';
    }

    /** @return array<string, mixed> extra firefly.security.* keys, seeded before boot */
    protected function securityOverrides(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The skeleton's own Blade views: WelcomeController renders `welcome`, which lives in the skeleton,
        // not in Testbench's laravel/resources/views. PREPENDED, not added: Testbench's vendored app ships
        // its own welcome.blade.php (the stock Laravel splash) at the head of the finder's path list, and
        // the finder takes the first hit — appending the skeleton's directory would render Laravel's page
        // under the skeleton's name and the "Hello, LaraFly" assertion would fail on a green boot.
        View::prependLocation(dirname(SkeletonApp::path()).'/resources/views');

        $this->defineFixtureRoutes();
        $this->prependFixturePrincipalFilter();
        $this->relocateSettingsOverrides();
    }

    /**
     * Three test-only routes. `submit` accepts POST only, so a browser's GET is the 405; `boom` throws a
     * wrapped exception so the "Caused by" chain has two links; nothing is registered under /api/, so
     * /api/browser-fixture/missing is the json-paths 404.
     */
    private function defineFixtureRoutes(): void
    {
        Route::post('/browser-fixture/submit', static fn (): string => 'submitted');
        Route::get('/browser-fixture/boom', static function (): never {
            throw new LogicException('The fixture failed on purpose.', 0, new RuntimeException('the inner cause'));
        });
    }

    private function prependFixturePrincipalFilter(): void
    {
        // Larastan narrows the contract to Testbench's foundation Kernel, which has prependMiddleware().
        $this->app()->make(HttpKernelContract::class)->prependMiddleware(FixturePrincipalFilter::class);
    }

    /**
     * The feature-switch console writes its override file under bootstrapPath('cache') — inside the
     * vendored Testbench app. Rebinding the console after boot points it at this class's temp dir instead
     * (AdminAction resolves the console lazily, at the first request), so a toggle made by a browser
     * scenario never lands in vendor/.
     */
    private function relocateSettingsOverrides(): void
    {
        /** @var ConfigRepository $repository */
        $repository = $this->app()->make('config');
        $config = new Config($repository);

        $this->app()->instance(SettingsConsole::class, new SettingsConsole(
            $config,
            $repository,
            SettingsSettings::fromConfig($config),
            (string) self::$cacheDir,
        ));
    }
}
