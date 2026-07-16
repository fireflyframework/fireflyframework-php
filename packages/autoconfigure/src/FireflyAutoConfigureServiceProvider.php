<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\Assembly\DefinitionAssembler;
use Firefly\AutoConfigure\Pass\AutoConfigDiscoveryPass;
use Firefly\AutoConfigure\Pass\AutoConfigurationsPass;
use Firefly\Config\Config;
use Firefly\Config\Profile\ProfileResolver;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Pass\ConditionPassOnePass;
use Firefly\Context\Pass\ConditionPassTwoPass;
use Firefly\Context\Pass\ContextRefreshedPass;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\FlushDefinitionsPass;
use Firefly\Context\Pass\InfrastructureStartPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Pass\UserConfigurationsPass;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * THE bootstrap layer FireflyServiceProvider promised but deferred ("a future bootstrap layer's job, NOT this
 * class's"). Auto-discovered via extra.laravel.providers. It (a) BINDS FireflyKernel + BootContext
 * (bound()-guarded, first-one-wins), (b) ensures the AutoConfigurationCollector is bound, and (c) contributes
 * the full boot pipeline — the eight core M4 passes + UserConfigurationsPass (the app/user scan) +
 * AutoConfigDiscoveryPass (200) + AutoConfigurationsPass (500) — through the inherited passes()/addPass() seam.
 * It modifies NOTHING in firefly/context (frozen 26.07.4).
 *
 * register() binds the kernel/context/collector FIRST, then defers to FireflyServiceProvider::register() (which
 * adds passes() to the now-bound kernel and wires the booting()/booted() phase split + ApplicationContext
 * singleton). parent::register() is safe HERE precisely because the kernel is already bound before the parent's
 * unguarded make(FireflyKernel::class) runs — unlike a bare AutoConfiguration candidate, which is why that base
 * class deliberately never calls parent::register().
 *
 * App scan by convention (Spring-Boot style, no app-authored provider required): user PSR-4 roots come from
 * `firefly.scan.paths`. If compiled app manifests are configured and present (`firefly.cache.*`) they are
 * LOADED with zero reflection (production); otherwise the roots are scanned in-process (dev), with reflection
 * confined to the M2/M4 scanner classes. When neither is set, the scan is empty and boot still succeeds.
 */
final class FireflyAutoConfigureServiceProvider extends FireflyServiceProvider
{
    /** @var array{0: ComponentManifest, 1: ContextManifest}|null memoized so the app is scanned at most once */
    private ?array $appManifests = null;

    public function register(): void
    {
        $this->bindBootContextAndKernel();
        $this->collector(); // ensure the collector is bound before any candidate or pass reads it

        parent::register(); // contributes passes() to the now-bound kernel + wires booting()/booted()
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        [$components, $context] = $this->resolveAppManifests();
        $assembler = new DefinitionAssembler;
        $collector = $this->collector();

        return [
            new UserConfigurationsPass($assembler->assemble($components, $context, DefinitionSource::User)),
            new ConditionPassOnePass,
            new AutoConfigDiscoveryPass($collector, $assembler),
            new AutoConfigurationsPass($collector),
            new ConditionPassTwoPass,
            new FlushDefinitionsPass(new ConfigPropertiesManifest([])),
            new RegisterBeanPostProcessorsPass,
            new RegisterEventListenersPass,
            new InfrastructureStartPass,
            new EagerSingletonsPass,
            new ContextRefreshedPass,
        ];
    }

    private function bindBootContextAndKernel(): void
    {
        if ($this->app->bound(FireflyKernel::class)) {
            return;
        }

        [, $context] = $this->resolveAppManifests();

        // BootContext requires the concrete Illuminate container; the ServiceProvider $app property is
        // only typed to the Application CONTRACT (which extends the container CONTRACT, not the concrete
        // class). At runtime $app is always the concrete container, so this narrows honestly rather than
        // via a cast/@var suppression.
        $container = $this->app;
        if (! $container instanceof Container) {
            throw new LogicException('FireflyAutoConfigureServiceProvider requires the concrete Illuminate container.');
        }

        $config = $this->config();
        $profiles = (new ProfileResolver)->resolve();

        $bootContext = new BootContext(
            container: $container,
            definitions: new BeanDefinitionRegistry,
            config: $config,
            profiles: $profiles,
            conditions: new ConditionEvaluator($config, $profiles),
            report: new ConditionEvaluationReport,
            contextManifest: $context,
        );

        $this->app->instance(FireflyKernel::class, new FireflyKernel($bootContext));
    }

    /**
     * @return array{0: ComponentManifest, 1: ContextManifest}
     */
    private function resolveAppManifests(): array
    {
        return $this->appManifests ??= $this->computeAppManifests();
    }

    /**
     * @return array{0: ComponentManifest, 1: ContextManifest}
     */
    private function computeAppManifests(): array
    {
        $config = $this->config();

        $componentPath = $config->get('firefly.cache.component_manifest');
        $contextPath = $config->get('firefly.cache.context_manifest');

        if (is_string($componentPath) && is_string($contextPath) && is_file($componentPath) && is_file($contextPath)) {
            return [ComponentManifest::load($componentPath), ContextManifest::load($contextPath)];
        }

        /** @var array<string,string> $paths */
        $paths = $config->get('firefly.scan.paths', []);
        if ($paths !== []) {
            return (new AutoConfigManifestCompiler)->scan($paths);
        }

        return [new ComponentManifest([]), new ContextManifest([])];
    }

    private function config(): Config
    {
        /** @var Repository $repository */
        $repository = $this->app->make('config');

        return new Config($repository);
    }

    private function collector(): AutoConfigurationCollector
    {
        if (! $this->app->bound(AutoConfigurationCollector::class)) {
            $this->app->instance(AutoConfigurationCollector::class, new AutoConfigurationCollector);
        }

        /** @var AutoConfigurationCollector */
        return $this->app->make(AutoConfigurationCollector::class);
    }
}
