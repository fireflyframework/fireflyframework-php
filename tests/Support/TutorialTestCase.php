<?php

declare(strict_types=1);

namespace Firefly\Tests\Support;

use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\EventSourcing\EventSourcingServiceProvider;
use Firefly\EventSourcing\EventSourcingWiringProvider;
use Firefly\EventSourcing\Manifest\EventSourcingManifest;
use Firefly\EventSourcing\Scanner\EventSourcingScanner;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\OrchestrationServiceProvider;
use Firefly\Orchestration\OrchestrationWiringProvider;
use Firefly\Orchestration\Scanner\OrchestrationScanner;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Scheduling\SchedulingServiceProvider;
use Firefly\Scheduling\SchedulingWiringProvider;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;

/**
 * Boots the tutorial's own app/ tree — tests/Fixtures/Tutorial/app, in the reader's `App\` namespace — through the
 * real pipeline a created application gets: the dev scan path (firefly.scan.paths) for its beans, the route scanner
 * for its #[RestController], the orchestration scanner for its #[Saga] and the event-sourcing scanner for its
 * #[EventType] events (what firefly:cache would compile), and the memory defaults for orchestration persistence, the
 * event store and the event bus, exactly as the skeleton ships them.
 *
 * The fixture classes are not under any Composer PSR-4 root (they declare `App\…`, not `Firefly\Tests\…`), so this
 * harness registers a test-only autoloader for them before the application boots.
 */
abstract class TutorialTestCase extends FireflyTestCase
{
    private static bool $autoloaderRegistered = false;

    public static function appPath(): string
    {
        return dirname(__DIR__).'/Fixtures/Tutorial/app';
    }

    /** @return array<string, string> */
    public static function psr4(): array
    {
        return ['App\\' => self::appPath()];
    }

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            ResilienceServiceProvider::class,
            SchedulingServiceProvider::class,
            SchedulingWiringProvider::class,
            EdaServiceProvider::class,
            EdaWiringProvider::class,
            OrchestrationServiceProvider::class,
            OrchestrationWiringProvider::class,
            EventSourcingServiceProvider::class,
            EventSourcingWiringProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.scan.paths' => self::psr4(),
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $app->instance(RouteManifest::class, new RouteManifest((new RouteScanner)->scan(self::psr4())));
        $app->instance(OrchestrationManifest::class, (new OrchestrationScanner)->scan(self::psr4()));
        $app->instance(EventSourcingManifest::class, (new EventSourcingScanner)->scan(self::psr4()));
    }

    protected function setUp(): void
    {
        self::registerAutoloader();

        parent::setUp();
    }

    private static function registerAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        self::$autoloaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            if (! str_starts_with($class, 'App\\')) {
                return;
            }

            $file = self::appPath().'/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
