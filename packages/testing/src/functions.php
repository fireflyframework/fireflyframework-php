<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\Validator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

if (! function_exists('fireflyApplication')) {
    /**
     * Boot a bare Firefly app the way Family B tests do — AutoConfigure registered FIRST, then the given
     * providers — with an opt-in "missing-bindings menu" a real host app would supply. Config is seeded
     * BEFORE FireflyAutoConfigureServiceProvider::register() so firefly.scan.paths is visible to the
     * memoized component scan (same eager-ordering rule FireflyTestCase::resolveApplicationConfiguration
     * follows for the testbench boot path).
     *
     * @param  array<string,mixed>  $config  full config array (defaults to ['firefly' => []])
     * @param  list<class-string<ServiceProvider>>  $providers  registered after AutoConfigure
     * @param  array<class-string,object>  $bindings  $app->instance(...) overrides bound before registration
     * @param  list<'cache'|'validation'|'http'>  $needs  standard missing-bindings menu, bind-if-not-bound
     */
    function fireflyApplication(array $config = [], array $providers = [], array $bindings = [], array $needs = []): Application
    {
        $app = new Application;
        $app->instance('config', new Repository($config === [] ? ['firefly' => []] : $config));

        if (in_array('cache', $needs, true) && ! $app->bound(Cache::class)) {
            $app->instance(Cache::class, new CacheRepository(new ArrayStore));
        }
        if (in_array('validation', $needs, true) && ! $app->bound(Validator::class)) {
            $app->instance(Validator::class, new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))));
        }
        if (in_array('http', $needs, true) && ! $app->bound(HttpKernelContract::class)) {
            $app->instance(HttpKernelContract::class, new FoundationHttpKernel($app, $app->make(Router::class)));
        }

        foreach ($bindings as $abstract => $instance) {
            $app->instance($abstract, $instance);
        }

        // AutoConfigure ALWAYS first: it binds FireflyKernel/BootContext (bound()-guarded) and the boot
        // pipeline passes(); every capability provider's own register() assumes the kernel already exists.
        $app->register(new FireflyAutoConfigureServiceProvider($app));
        foreach ($providers as $provider) {
            $app->register(new $provider($app));
        }

        $app->boot();

        return $app;
    }
}

if (! function_exists('bootFireflyApp')) {
    /**
     * @param  array<string,mixed>  $config
     * @param  list<class-string<ServiceProvider>>  $providers
     * @param  array<class-string,object>  $bindings
     * @param  list<'cache'|'validation'|'http'>  $needs
     */
    function bootFireflyApp(array $config = [], array $providers = [], array $bindings = [], array $needs = []): ApplicationContext
    {
        /** @var ApplicationContext $context */
        $context = fireflyApplication($config, $providers, $bindings, $needs)->make(ApplicationContext::class);

        return $context;
    }
}
