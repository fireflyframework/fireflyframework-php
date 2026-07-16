<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure;

use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * Base for every capability package's discovered auto-configuration provider. A subclass declares only WHICH
 * compiled manifests describe its own #[Configuration] classes; register() (final) records that candidacy into
 * the shared, container-bound AutoConfigurationCollector and does NOTHING else.
 *
 * WHY register() does NOT call parent::register() (a deliberate departure from the naive "extend and super"):
 * FireflyServiceProvider::register()'s very first statement is an UNGUARDED
 * `$this->app->make(FireflyKernel::class)`. Laravel does not order auto-discovered providers, so a candidate
 * may register BEFORE the bootstrap FireflyAutoConfigureServiceProvider binds the kernel. In that window,
 * make(FireflyKernel::class) would try to auto-resolve FireflyKernel -> BootContext ->
 * Firefly\Config\Profile\Profiles, whose constructor `public array $active` the container cannot resolve —
 * aborting boot. A candidate's ONLY register-time responsibility is candidacy; binding the kernel and
 * contributing the boot pipeline are the bootstrap provider's job (it extends FireflyServiceProvider and DOES
 * call the parent path, but only after binding the kernel first). register() is `final` so no subclass can
 * reintroduce that hazard.
 */
abstract class AutoConfiguration extends FireflyServiceProvider
{
    /** Compiled ComponentManifest describing THIS package's own #[Configuration] classes (loaded, not scanned). */
    abstract protected function componentManifestPath(): string;

    /** Compiled ContextManifest describing THIS package's own #[ConditionalOn*] metadata (loaded, not scanned). */
    abstract protected function contextManifestPath(): string;

    final public function register(): void
    {
        $this->collector()->add(new AutoConfigurationCandidate(
            provider: static::class,
            componentManifestPath: $this->componentManifestPath(),
            contextManifestPath: $this->contextManifestPath(),
        ));
    }

    /**
     * First-one-wins, bound()-guarded — the exact idiom FireflyServiceProvider::bindApplicationEventPublisher()
     * uses. Whether a candidate or the bootstrap creates it first, everyone shares the one instance.
     */
    private function collector(): AutoConfigurationCollector
    {
        if (! $this->app->bound(AutoConfigurationCollector::class)) {
            $this->app->instance(AutoConfigurationCollector::class, new AutoConfigurationCollector);
        }

        /** @var AutoConfigurationCollector */
        return $this->app->make(AutoConfigurationCollector::class);
    }
}
