<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Validation\Validator;
use Firefly\Web\WebServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;

it('boots a bare cqrs app with AutoConfigure first and an explicit binding', function () {
    $context = bootFireflyApp(
        config: ['firefly' => ['cqrs' => []]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class],
        bindings: [HandlerManifest::class => new HandlerManifest([], [])],
    );

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->has(HandlerManifest::class))->toBeTrue();
});

it('applies the validation+http missing-bindings menu via $needs for a web boot', function () {
    $app = fireflyApplication(
        config: ['firefly' => []],
        providers: [WebServiceProvider::class],
        needs: ['validation', 'http'],
    );

    expect($app)->toBeInstanceOf(Application::class)
        ->and($app->bound(Validator::class))->toBeTrue()
        ->and($app->bound(HttpKernelContract::class))->toBeTrue()
        ->and($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});

it('binds an isolated ArrayStore-backed cache fallback when needs: [\'cache\'] is requested', function () {
    // Application::__construct() pre-aliases Cache::class to 'cache.store' via registerCoreContainerAliases(),
    // so a naive ! $app->bound(Cache::class) guard is fooled into thinking the capability is already
    // satisfied and never installs the fallback. Resolving getStore() as an ArrayStore proves the harness's
    // own isolated CacheRepository(new ArrayStore) was actually bound, not the framework's aliased store.
    $app = fireflyApplication(needs: ['cache']);

    /** @var CacheRepository $cache */
    $cache = $app->make(Cache::class);

    expect($cache)->toBeInstanceOf(Cache::class)
        ->and($cache->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('lets an explicit cache binding override the needs: [\'cache\'] fallback', function () {
    $myRepo = new CacheRepository(new ArrayStore);

    $app = fireflyApplication(
        bindings: [Cache::class => $myRepo],
        needs: ['cache'],
    );

    expect($app->make(Cache::class))->toBe($myRepo);
});
