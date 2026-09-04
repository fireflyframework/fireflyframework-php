<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * The other half of SkeletonExampleTestCase: the shipped skeleton booted UNCACHED, by in-process scan.
 *
 * Both paths have to work and they are genuinely different code. With a compiled artifact present the
 * framework `require`s a pure-array manifest; with none it runs the scanner in-process at boot and builds
 * the same manifest from reflection. A DTO shape or a docblock the SCANNER cannot read but the compiled
 * manifest already contains would pass every cached-path assertion and still break the first request a
 * developer makes — because the first request a developer makes is under `artisan serve`, BEFORE they have
 * run `firefly:cache` even once. (The skeleton's composer.json does run it in post-create-project-cmd, but
 * every subsequent edit to app/ invalidates it, and nothing forces a re-run.)
 *
 * So: `firefly.scan.paths` set, `firefly.cache.path` pointed at a directory with nothing in it.
 */
abstract class SkeletonScannedBootTestCase extends FireflyDatabaseTestCase
{
    protected function setUp(): void
    {
        SkeletonApp::register();

        parent::setUp();

        SkeletonApp::migrate();
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            // The sample repository extends EloquentRepository, so the data layer has to be wired for the
            // resource to answer at all — the uncached path resolves the same beans the cached one does.
            DataServiceProvider::class,
        ];
    }

    /** @return array<string,mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.scan.paths' => SkeletonApp::psr4(),
            // A path that cannot hold artifacts, so every `is_file()` probe in the boot path answers false
            // and the scan branch is the one under test — rather than silently reusing whatever a previous
            // test in this process happened to compile.
            'firefly.cache.path' => sys_get_temp_dir().'/firefly-skeleton-uncached-'.bin2hex(random_bytes(6)),
            // The 404 and 422 cases below are provoked on purpose; see SkeletonExampleTestCase for why the
            // channel is kept but raised rather than removed.
            'logging.channels.errorlog' => ['driver' => 'errorlog', 'level' => 'emergency'],
        ];
    }
}
