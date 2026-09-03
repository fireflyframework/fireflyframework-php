<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Support;

use Firefly\OpenApi\OpenApiServiceProvider;
use Firefly\OpenApi\OpenApiWiringProvider;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;

/**
 * Boots the package over testbench with a REAL web layer, so the spec and viewer routes are mounted on the
 * real Router and dispatched through the real HTTP kernel — and, more importantly, so RouteManifest and
 * ConstraintManifest are populated the way a real uncached app populates them: by AppScan finding
 * `firefly.scan.paths` and running the scanners in-process. The document these tests assert against is
 * therefore produced by the same path a developer's `php artisan serve` would use.
 *
 * The gates are template methods rather than post-boot `config()->set()` calls for the reason
 * ActuatorCapstoneTestCase documents at length: OpenApiProperties is a singleton #[Bean] resolved at
 * FlushDefinitions (650), and the routes are mounted from it at WiringPasses (1000). Both happen once, at
 * boot, so changing the config inside a test body can never move a route that is already mounted — a
 * different gate means a different boot, which means a different test case class.
 */
abstract class OpenApiCapstoneTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            OpenApiServiceProvider::class,
            OpenApiWiringProvider::class,
        ];
    }

    protected function configOverrides(): array
    {
        return [
            'firefly.scan.paths' => FixtureDocument::psr4(),
            'firefly.openapi.enabled' => $this->openApiEnabled(),
            'firefly.openapi.viewer.enabled' => $this->viewerEnabled(),
            'firefly.openapi.title' => 'Orders API',
            'firefly.openapi.version' => '1.2.3',
        ];
    }

    protected function openApiEnabled(): bool
    {
        return true;
    }

    protected function viewerEnabled(): bool
    {
        return true;
    }
}
