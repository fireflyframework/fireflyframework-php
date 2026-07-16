<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\Support;

use Firefly\AutoConfigure\AutoConfiguration;
use Illuminate\Contracts\Foundation\Application;

/**
 * A SECOND, distinct AutoConfiguration provider identity for the end-to-end tests, alongside
 * {@see ManifestPathAutoConfiguration}. It is byte-for-byte behaviourally identical — its only
 * purpose is to carry a DIFFERENT class name.
 *
 * WHY a distinct class is required for multi-candidate scenarios: a candidate's provider FQCN is the
 * dedupe key at BOTH layers of the boot path. Laravel's Application::register() short-circuits on the
 * PROVIDER CLASS NAME (registering a second instance of the same class returns the first and never
 * calls its register()), and AutoConfigurationCollector::add() keys candidates by
 * AutoConfigurationCandidate::$provider (which AutoConfiguration::register() sets to `static::class`).
 * In production each capability package ships its OWN AutoConfiguration subclass, so two real
 * candidates always have two distinct FQCNs and neither layer collapses them. An e2e test that
 * registered two `ManifestPathAutoConfiguration` instances would silently collapse to ONE candidate,
 * quietly defeating any scenario that needs two competing/ordered auto-configs (scenarios 2 and 4).
 * Registering candidate 0 as ManifestPathAutoConfiguration and candidate 1 as this class mirrors
 * production's one-class-per-package reality and keeps both candidates distinct.
 */
final class SecondManifestPathAutoConfiguration extends AutoConfiguration
{
    public function __construct(
        Application $app,
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
}
