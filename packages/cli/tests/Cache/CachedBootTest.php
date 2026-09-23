<?php

declare(strict_types=1);

use Firefly\Cli\Boot\FireflyCacheServiceProvider;
use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Cli\Tests\Fixtures\App\DemoConfigProperties;
use Firefly\Cli\Tests\Fixtures\App\DemoLayeredService;
use Firefly\Cli\Tests\Fixtures\App\DemoSecuredService;
use Firefly\Cli\Tests\Fixtures\App\DemoTimedService;
use Firefly\Cli\Tests\Fixtures\App\DemoTransactionalService;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Observability\Method\ObservabilityAdviceSource;
use Firefly\Resilience\Method\ResilienceAdviceSource;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateValidationFactory;

/** @return array<string,string> */
function cachedBootPsr4(): array
{
    return ['Firefly\\Cli\\Tests\\Fixtures\\App\\' => dirname(__DIR__).'/Fixtures/App'];
}

it('emits proxy class files + a loadable classmap', function () {
    $dir = sys_get_temp_dir().'/firefly-cache-'.bin2hex(random_bytes(6));

    $report = (new ManifestCacheWriter)->write(cachedBootPsr4(), $dir);

    // (a) proxy files + a loadable proxies.php classmap emitted.
    expect($report->proxyCount)->toBeGreaterThan(0)
        ->and(is_file($dir.'/'.FireflyCachePaths::PROXY_MAP))->toBeTrue();

    /** @var array<string,string> $map */
    $map = require $dir.'/'.FireflyCachePaths::PROXY_MAP;
    $proxyClass = DemoTransactionalService::class.'__FireflyTransactionalProxy';
    expect($map)->toHaveKey($proxyClass)
        ->and(is_file($map[$proxyClass]))->toBeTrue();
});

/*
 | firefly:cache used to plan through TransactionalScanner alone: security-methods.php listed every rule while
 | proxies.php named only the #[Transactional] classes and no proxy-plan.php was written, so a cached boot took
 | DataAutoConfiguration::proxyPlan()'s transactional-only bridge and a #[Service] carrying nothing but
 | #[PreAuthorize] was handed out bare — its rule compiled, enforced by nothing, and nothing logged. The plan is
 | now compiled through every AdviceSource, exactly as the uncached boot collects them.
 */
it('emits proxy-plan.php naming the security-only and metric-only services beside the transactional one', function () {
    $dir = sys_get_temp_dir().'/firefly-cache-'.bin2hex(random_bytes(6));

    $report = (new ManifestCacheWriter)->write(cachedBootPsr4(), $dir);

    expect($report->files)->toContain($dir.'/'.FireflyCachePaths::PROXY_PLAN)
        ->and(is_file($dir.'/'.FireflyCachePaths::PROXY_PLAN))->toBeTrue();

    $plan = ProxyPlan::load($dir.'/'.FireflyCachePaths::PROXY_PLAN);

    expect($plan->hasProxyFor(DemoTransactionalService::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(DemoTransactionalService::class)))->toBe(['tx'])
        ->and($plan->hasProxyFor(DemoSecuredService::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(DemoSecuredService::class)))->toBe([MethodSecurityAdviceSource::ID])
        ->and($plan->methodsFor(DemoSecuredService::class)['secret'][0]['row']['expression'])->toBe("hasRole('ADMIN')")
        // …and the same for the metric-only #[Service]: the third source in planner(), which nothing else
        // in this package's suite exercises. Deleting `new ObservabilityAdviceSource` from planner() used to
        // leave the whole suite green while a cached app recorded no method meter at all.
        ->and($plan->hasProxyFor(DemoTimedService::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(DemoTimedService::class)))->toBe([ObservabilityAdviceSource::ID])
        ->and($plan->methodsFor(DemoTimedService::class)['measured'][0]['row']['timed'])->toBe(['name' => 'demo.timed', 'tags' => [], 'longTask' => false]);

    /** @var array<string,string> $map */
    $map = require $dir.'/'.FireflyCachePaths::PROXY_MAP;

    // One proxy per PLANNED class, generated with the security link baked in — not one per #[Transactional] class.
    expect($report->proxyCount)->toBe(count($plan->classes()))
        ->and($map)->toHaveKey(DemoSecuredService::class.ProxyPlan::PROXY_SUFFIX)
        ->and((string) file_get_contents($map[DemoSecuredService::class.ProxyPlan::PROXY_SUFFIX]))->toContain('__fireflySecurityInterceptor');

    // ObservabilityAdviceSource::render() is the only thing that emits the descriptor literal into the
    // generated proxy, and it runs on the CACHED path alone — the observability capstones take the scan
    // branch by design. Asserting the generated source proves the literal parses and names the right class;
    // the property name comes from Advice::property() over the id 'metrics'.
    $timedProxy = (string) file_get_contents($map[DemoTimedService::class.ProxyPlan::PROXY_SUFFIX]);

    expect($map)->toHaveKey(DemoTimedService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($timedProxy)->toContain('__fireflyMetricsInterceptor')
        ->and($timedProxy)->toContain('\\Firefly\\Observability\\Method\\ObservabilityMethodDescriptor::fromArray(');
});

/*
 | The chain, on the compiled artifact. ObservabilityAdviceSource's order 50 is the constant every claim about
 | #[Timed] rests on — "a refusal is still counted", "the commit is inside the timer" — and a class carrying
 | ONE advice can never contradict it, which is what every other fixture here carries. DemoLayeredService
 | carries all four on one method, so ProxyPlanner's sort and ProxyPlan::adviceFor()'s sort both have to
 | agree on the whole sequence before this passes. Changing 50 to 150 leaves every other test in the
 | monorepo green and turns method metrics into a meter that goes quiet exactly when an operator needs it.
 |
 | ResilienceAdviceSource's 200 is the fourth link and the same kind of constant: INSIDE security, so a call
 | a #[PreAuthorize] refuses never spends a retry budget or trips a breaker, and OUTSIDE the transaction, so
 | each retry attempt gets a transaction of its own. Until this fixture carried #[Retry], deleting
 | `new ResilienceAdviceSource` from planner() left the monorepo green while every `firefly:cache`d
 | application lost all six resilience attributes — the proxies still generated, just with no resilience
 | advice in them at all.
 */
it('chains metrics OUTSIDE security, security outside resilience and resilience outside the transaction', function () {
    $dir = sys_get_temp_dir().'/firefly-cache-'.bin2hex(random_bytes(6));

    (new ManifestCacheWriter)->write(cachedBootPsr4(), $dir);

    $plan = ProxyPlan::load($dir.'/'.FireflyCachePaths::PROXY_PLAN);
    $advice = $plan->adviceFor(DemoLayeredService::class);

    // The advice set on the class, outermost first, and the orders that put it in that sequence.
    expect(array_keys($advice))->toBe([ObservabilityAdviceSource::ID, MethodSecurityAdviceSource::ID, ResilienceAdviceSource::ID, Advice::TRANSACTIONAL])
        ->and($advice[ObservabilityAdviceSource::ID]->order)->toBe(50)
        ->and($advice[ResilienceAdviceSource::ID]->order)->toBe(200)
        ->and($advice[ObservabilityAdviceSource::ID]->order)->toBeLessThan($advice[MethodSecurityAdviceSource::ID]->order)
        ->and($advice[MethodSecurityAdviceSource::ID]->order)->toBeLessThan($advice[ResilienceAdviceSource::ID]->order)
        ->and($advice[ResilienceAdviceSource::ID]->order)->toBeLessThan($advice[Advice::TRANSACTIONAL]->order);

    // …and the per-method rows, which are what ProxyPlanner::proxyMethods() turns into the generated chain:
    // the same sequence, so the emitted proceed() really does reach the metric link first.
    expect(array_column($plan->methodsFor(DemoLayeredService::class)['all'], 'advice'))
        ->toBe([ObservabilityAdviceSource::ID, MethodSecurityAdviceSource::ID, ResilienceAdviceSource::ID, Advice::TRANSACTIONAL]);

    // ResilienceAdviceSource::render() runs on the CACHED path alone — the resilience capstones take the
    // scan branch by design — so this is the only place the emitted descriptor literal is proved to parse
    // and to name the right class. Same argument as the ObservabilityMethodDescriptor assertion above.
    /** @var array<string,string> $map */
    $map = require $dir.'/'.FireflyCachePaths::PROXY_MAP;
    $layeredProxy = (string) file_get_contents($map[DemoLayeredService::class.ProxyPlan::PROXY_SUFFIX]);

    expect($layeredProxy)->toContain('__fireflyResilienceInterceptor')
        ->and($layeredProxy)->toContain('\\Firefly\\Resilience\\Method\\ResilienceMethodDescriptor::fromArray(')
        ->and($plan->methodsFor(DemoLayeredService::class)['all'][2]['row']['retry'])->toBe('demo');
});

it('boots the fixture app on the CACHED zero-reflection path with a working #[Transactional] proxy', function () {
    $dir = sys_get_temp_dir().'/firefly-cache-'.bin2hex(random_bytes(6));
    (new ManifestCacheWriter)->write(cachedBootPsr4(), $dir);

    // A standalone sqlite connection so the #[Transactional] proxy's real DB transaction can run.
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    // The Category-C fixture reads the cache dir from the env (a fixture has no fixed base_path()).
    putenv('FIREFLY_CACHE_DIR='.$dir);

    // The app sets ONLY the two existing config keys (R1) + the cache path; NO firefly.scan.paths — so
    // FireflyAutoConfigureServiceProvider::computeAppManifests() MUST take the ::load() branch (the compiled
    // component/context path), proving zero-reflection. There is no scan fallback to fall back to.
    $app = fireflyApplication(
        config: [
            'firefly' => [
                'cache' => [
                    'path' => $dir,
                    'component_manifest' => $dir.'/'.FireflyCachePaths::COMPONENT,
                    'context_manifest' => $dir.'/'.FireflyCachePaths::CONTEXT,
                ],
            ],
            // Seeds a value that DIFFERS from DemoConfigProperties's constructor default ('hello'), so
            // assertion (d) below can distinguish "the DTO was POPULATED FROM CONFIG" from "the DTO merely
            // resolved via bare autowiring of its all-default constructor" (the tautology a review found in
            // the original version of this test).
            'demo' => ['greeting' => 'from-config-value'],
        ],
        providers: [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            DataServiceProvider::class,
            FireflyCacheServiceProvider::class,
        ],
        // ValidationAutoConfiguration's validator() bean is an eager singleton needing the Illuminate validation
        // Factory (its #[ConditionalOnMissingBean(Validator)] does not see a plain $app->instance() port binding),
        // so bind it explicitly — exactly as firefly/validation's own ShippedProviderBootTest does.
        bindings: [ValidationFactory::class => new IlluminateValidationFactory(new Translator(new ArrayLoader, 'en'))],
        needs: ['cache', 'http'],
    );
    $app->instance('db', $capsule->getDatabaseManager());

    // The #[Transactional] proxy below reaches the database through the DB FACADE, so this test needs the
    // facade root pointed at its own bare application — and needs that to be the whole of its dealings with
    // a global. withFireflyFacadeApplication() owns both ends (packages/testing/src/functions.php): it
    // clears the resolved instances and installs $app on the way in, and clears again and restores the
    // previous root on the way out, so this test neither inherits a predecessor's facade state nor leaves
    // its own behind.
    //
    // Both ends are load-bearing, and each was a real failure rather than a precaution. INWARD:
    // Facade::$resolvedInstance survives a change of Facade::$app, so without the clear, `DB::connection()`
    // inside the proxy reuses whatever DatabaseManager the last Testbench test in the process left behind —
    // one bound to a container that has since been flushed — and dies with `Target class [config] does not
    // exist` from inside TransactionTemplate, which is what `pest packages/data packages/cli` and `pest
    // packages/resilience packages/cli` used to report. OUTWARD: leaving this fixture application installed
    // as the root made every later test in the process inherit a container this test was done with, so the
    // trap simply pointed the other way. Neither direction depends on which suites happen to run alongside.
    withFireflyFacadeApplication($app, function () use ($app): void {
        cachedBootAssertions($app);
    });
});

/**
 * The body of the cached-boot test, run by withFireflyFacadeApplication() with the facade root installed.
 */
function cachedBootAssertions(Application $app): void
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    // (b) the app booted from the compiled component/context (no scan.paths were configured, so the bean can
    // only have come from the ::load()ed component manifest).
    expect($app->make('config')->get('firefly.scan.paths'))->toBeNull()
        ->and($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->has(DemoTransactionalService::class))->toBeTrue();

    // (c) the #[Transactional] proxy resolves (the generated subclass, not the bare class) and actually works.
    // The proxy IS-A DemoTransactionalService, so the @var is honest (a subclass of the resolved type).
    /** @var DemoTransactionalService $service */
    $service = $context->get(DemoTransactionalService::class);
    expect($service::class)->toBe(DemoTransactionalService::class.'__FireflyTransactionalProxy')
        ->and($service->save('x'))->toBe('saved:x');

    // (c') a class planned for SECURITY advice alone is proxied on the cached path too — and, with no
    // security provider in this app, its rule is inert: the interceptor bean is absent, so the proxy runs a
    // pass-through link and the call goes through. Enforcement is proven in packages/security.
    /** @var DemoSecuredService $secured */
    $secured = $context->get(DemoSecuredService::class);
    expect($secured::class)->toBe(DemoSecuredService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($secured->secret())->toBe('secret');

    // (c'') and a class planned for METRIC advice alone is proxied and callable on the cached path, inert for
    // the same reason: no observability provider in this app, so InterceptorRegistry hands the metrics link a
    // pass-through (the advice declares inertWhenUnbound). Recording is proven in packages/observability.
    /** @var DemoTimedService $timed */
    $timed = $context->get(DemoTimedService::class);
    expect($timed::class)->toBe(DemoTimedService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($timed->measured())->toBe('measured');

    // Category C: the compiled TransactionalManifest is populated (the #[Configuration] #[Bean] loaded it).
    /** @var TransactionalManifest $manifest */
    $manifest = $context->get(TransactionalManifest::class);
    expect($manifest->hasProxyFor(DemoTransactionalService::class))->toBeTrue();

    // Category B: a manifest loaded from the cached file lands in the container on the cached path. has()/
    // bound() alone does NOT discriminate here — WebServiceProvider ALSO binds an empty default RouteManifest
    // guarded by `if (! bound(RouteManifest::class))` (packages/web/src/WebServiceProvider.php), so bound() is
    // true whether or not FireflyCacheServiceProvider's override ran. The real discriminator is CONTENT: the
    // fixture's DemoController declares #[GetMapping('/demo')], so only the COMPILED manifest — not the empty
    // default — contains that path. This assertion FAILS if FireflyCacheServiceProvider's
    // `$app->instance(RouteManifest::class, RouteManifest::load($path))` binding block is removed.
    /** @var RouteManifest $routes */
    $routes = $context->get(RouteManifest::class);
    expect(array_map(static fn (RouteDescriptor $route): string => $route->path, $routes->all()))
        ->toContain('/demo');

    // (d) a #[ConfigProperties] DTO resolves on the cached path (bound by FireflyCacheServiceProvider via
    // ConfigRegistrar from the emitted config-properties.php — the only app-side binding path for it).
    //
    // Discriminator #1: has()/bound() is true ONLY for an explicit singleton()/instance() binding, which
    // ONLY ConfigRegistrar performs for #[ConfigProperties] DTOs (packages/config/src/Registrar/
    // ConfigRegistrar.php) — unlike RouteManifest above, a plain #[ConfigProperties] DTO has NO bound()-guarded
    // default, so a bare autowired resolution (Laravel's reflection-based make(), which never populates
    // $bindings/$instances for a class that was never explicitly bound) would leave has() false. This alone
    // FAILS if FireflyCacheServiceProvider's ConfigRegistrar block is removed.
    expect($context->has(DemoConfigProperties::class))->toBeTrue();

    // Discriminator #2 (stronger): the resolved DTO's greeting reflects the SEEDED CONFIG VALUE
    // ('from-config-value'), not the constructor default ('hello') — proving the binding was genuinely
    // POPULATED FROM the compiled config-properties.php, not merely resolved as a bare autowired class whose
    // default happens to match.
    /** @var DemoConfigProperties $props */
    $props = $context->get(DemoConfigProperties::class);
    expect($props->greeting)->toBe('from-config-value');
}
