<?php

declare(strict_types=1);

use Firefly\Cli\Boot\FireflyCacheServiceProvider;
use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Cli\Tests\Fixtures\App\DemoConfigProperties;
use Firefly\Cli\Tests\Fixtures\App\DemoTransactionalService;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
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
        config: ['firefly' => [
            'cache' => [
                'path' => $dir,
                'component_manifest' => $dir.'/'.FireflyCachePaths::COMPONENT,
                'context_manifest' => $dir.'/'.FireflyCachePaths::CONTEXT,
            ],
        ]],
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
    Facade::setFacadeApplication($app);

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

    // Category C: the compiled TransactionalManifest is populated (the #[Configuration] #[Bean] loaded it).
    /** @var TransactionalManifest $manifest */
    $manifest = $context->get(TransactionalManifest::class);
    expect($manifest->hasProxyFor(DemoTransactionalService::class))->toBeTrue();

    // (d) a #[ConfigProperties] DTO resolves on the cached path (bound by FireflyCacheServiceProvider via
    // ConfigRegistrar from the emitted config-properties.php — the only app-side binding path for it).
    /** @var DemoConfigProperties $props */
    $props = $context->get(DemoConfigProperties::class);
    expect($props->greeting)->toBe('hello');
});
