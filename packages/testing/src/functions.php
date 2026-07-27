<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Testing\Double\RecordingEventPublisher;
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
     * @param  list<'cache'|'validation'|'http'>  $needs  standard missing-bindings menu; binds a fresh
     *                                                    fallback instance for each requested capability
     *                                                    (overridable by an explicit $bindings entry)
     */
    function fireflyApplication(array $config = [], array $providers = [], array $bindings = [], array $needs = []): Application
    {
        $app = new Application;
        $app->instance('config', new Repository($config === [] ? ['firefly' => []] : $config));

        // NOTE: a fresh Application::__construct() already calls registerCoreContainerAliases(), which
        // pre-aliases Illuminate\Contracts\Cache\Repository -> 'cache.store' and
        // Illuminate\Contracts\Validation\Factory -> 'validator'. That makes $app->bound(Cache::class) /
        // $app->bound(Validator::class) return true via isAlias() even though nothing was ever bound — so
        // these branches must NOT be guarded by ! $app->bound(...); they bind unconditionally when
        // requested. Container::instance() unsets the alias before binding, so this correctly installs the
        // isolated fallback instance in place of the aliased one. The explicit $bindings loop below runs
        // AFTER this and still wins if the caller also passes an explicit binding for the same abstract.
        if (in_array('cache', $needs, true)) {
            $app->instance(Cache::class, new CacheRepository(new ArrayStore));
        }
        if (in_array('validation', $needs, true)) {
            $app->instance(Validator::class, new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))));
        }
        if (in_array('http', $needs, true)) {
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

if (! function_exists('assertEventPublished')) {
    /**
     * @param  RecordingEventPublisher  $publisher
     * @param  array<string,mixed>  $payloadContains
     */
    function assertEventPublished(object $publisher, string $eventType, array $payloadContains = []): void
    {
        // toHavePublished() is registered at runtime by FireflyExpectations::register() (Pest\Concerns\
        // Extendable::extend(), invoked via dynamic __call) — PHPStan has no reflection extension that
        // knows about expectations added this way, so it can't see the method on Pest\Expectation even
        // though it genuinely exists once tests/Pest.php has run (proven by RecordingEventPublisherTest).
        // @phpstan-ignore method.notFound
        expect($publisher)->toHavePublished($eventType, $payloadContains);
    }
}

if (! function_exists('assertNoEventsPublished')) {
    /** @param RecordingEventPublisher $publisher */
    function assertNoEventsPublished(object $publisher): void
    {
        expect($publisher->published)->toBeEmpty('Expected no events to have been published.');
    }
}

if (! function_exists('is_docker_available')) {
    /** True iff `docker info` exits 0 (also true when DOCKER_HOST is set and reachable, since the docker CLI honors it). Never throws; returns false on any failure so it is safe to call with no Docker installed. */
    function is_docker_available(): bool
    {
        $result = 1;
        $output = [];
        exec('docker info >/dev/null 2>&1', $output, $result);

        return $result === 0;
    }
}

if (! function_exists('fireflyConfigFor')) {
    /**
     * Map a started testcontainer into a flat Firefly/Laravel config array (the @ServiceConnection analog).
     *
     * @return array<string,mixed>
     */
    function fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array
    {
        $config = [];
        if (method_exists($container, 'getHost')) {
            $config["{$prefix}.host"] = $container->getHost();
        }
        if (method_exists($container, 'getMappedPort')) {
            $config["{$prefix}.port"] = $container->getMappedPort();
        }
        if (method_exists($container, 'getUsername')) {
            $config["{$prefix}.username"] = $container->getUsername();
        }
        if (method_exists($container, 'getPassword')) {
            $config["{$prefix}.password"] = $container->getPassword();
        }
        if (method_exists($container, 'getDatabase')) {
            $config["{$prefix}.database"] = $container->getDatabase();
        }

        return $config;
    }
}
