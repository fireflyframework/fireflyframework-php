<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfiguration;
use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\AutoConfigure\Tests\Support\ManifestPathAutoConfiguration;
use Firefly\Context\Boot\FireflyKernel;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;

it('records candidacy into a container-bound collector at register() time — and never touches the kernel', function () {
    $app = new Application;

    $app->register(new ManifestPathAutoConfiguration($app, '/tmp/c.php', '/tmp/x.php'));

    // The collector exists and holds exactly this provider's candidacy.
    expect($app->bound(AutoConfigurationCollector::class))->toBeTrue();
    $collector = $app->make(AutoConfigurationCollector::class);
    expect($collector->all())->toHaveCount(1)
        ->and($collector->all()[0]->provider)->toBe(ManifestPathAutoConfiguration::class)
        ->and($collector->all()[0]->componentManifestPath)->toBe('/tmp/c.php')
        ->and($collector->all()[0]->contextManifestPath)->toBe('/tmp/x.php');

    // Critical: registering a candidate must NOT have caused FireflyKernel to be resolved/bound.
    // (FireflyServiceProvider::register()'s unguarded make(FireflyKernel::class) would abort boot here,
    //  because auto-resolving BootContext -> Profiles(public array $active) is impossible. This proves
    //  AutoConfiguration deliberately does NOT call parent::register().)
    expect($app->bound(FireflyKernel::class))->toBeFalse();
});

it('a second AutoConfiguration reuses the same collector instance (first-one-wins bound()-guard)', function () {
    $app = new Application;
    $app->register(new ManifestPathAutoConfiguration($app, '/tmp/a-c.php', '/tmp/a-x.php'));
    $first = $app->make(AutoConfigurationCollector::class);

    // Deliberately a SECOND, distinct AutoConfiguration subclass rather than a second instance of
    // ManifestPathAutoConfiguration: Illuminate\Foundation\Application::register() dedupes providers by
    // get_class($provider), so a second instance of the SAME class would never have its register() invoked
    // at all — and even if it were, AutoConfigurationCollector deliberately dedupes candidates by provider
    // FQCN (see AutoConfigurationCollectorTest), so two same-class candidates would collapse to one entry
    // regardless. Only two DIFFERENT provider classes can exercise "two independent candidates land in the
    // one shared, bound()-guarded collector instance".
    $second = new class($app, '/tmp/b-c.php', '/tmp/b-x.php') extends AutoConfiguration
    {
        public function __construct(
            ApplicationContract $app,
            private readonly string $componentPath,
            private readonly string $contextPath,
        ) {
            parent::__construct($app);
        }

        protected function componentManifestPath(): string
        {
            return $this->componentPath;
        }

        protected function contextManifestPath(): string
        {
            return $this->contextPath;
        }
    };
    $app->register($second);

    expect($app->make(AutoConfigurationCollector::class))->toBe($first)
        ->and($first->all())->toHaveCount(2);
});
