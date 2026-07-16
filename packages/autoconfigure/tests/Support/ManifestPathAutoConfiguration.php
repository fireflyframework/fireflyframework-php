<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\Support;

use Firefly\AutoConfigure\AutoConfiguration;
use Illuminate\Contracts\Foundation\Application;

/**
 * A concrete AutoConfiguration whose compiled-manifest paths are supplied per-instance, so a test can point a
 * candidate at manifests it just compiled to a temp file. A real capability package instead hard-codes its own
 * cache paths (see Firefly\Validation\ValidationServiceProvider).
 */
final class ManifestPathAutoConfiguration extends AutoConfiguration
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
