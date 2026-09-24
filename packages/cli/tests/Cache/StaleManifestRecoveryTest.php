<?php

declare(strict_types=1);

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Command\CacheCommand;
use Firefly\Cli\Command\ClearCommand;
use Firefly\Cli\Tests\Support\StaleApp;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 | Deleting a #[Component] and running `php artisan firefly:cache` has to be enough.
 |
 | It was not. The compiled `component.php` still named the class, ContainerRegistrar bound the interface it
 | implemented to a class autoloading could not find, and the boot the command performs before it can write
 | anything died with `Target class [...] does not exist` — pointing at a file the developer had already
 | deleted, from inside a bean they had not touched. `composer dump-autoload` could not recover it either,
 | because `package:discover` boots the application too. The recovery was `rm bootstrap/cache/firefly/*.php`,
 | then `composer dump-autoload`, then `firefly:cache`, in that order: a three-step incantation a developer
 | simply had to know. Observed on a real application on 2026-09-24.
 |
 | The fixture is built the way the developer's tree was (see StaleApp): the manifests are compiled by a real
 | subprocess from a real source tree, and the #[Primary] implementation is then deleted from disk — so the
 | class is genuinely absent from this process, not merely asserted to be.
 */

/**
 * The config a boot over the STALE compiled manifests needs: the deleted class's manifest, and the scan
 * roots firefly:cache recompiles from.
 *
 * @return array<string,mixed>
 */
function staleAppConfig(string $src, string $cache): array
{
    return [
        'firefly' => [
            'cache' => [
                'path' => $cache,
                'component_manifest' => $cache.'/'.FireflyCachePaths::COMPONENT,
                'context_manifest' => $cache.'/'.FireflyCachePaths::CONTEXT,
            ],
            'scan' => ['paths' => [StaleApp::NAMESPACE => $src]],
        ],
    ];
}

/**
 * Runs $body with $argv installed as the process's command line, and puts back whatever was there.
 *
 * `$_SERVER['argv']` is what AppScan reads to decide whether this boot is one of the two that repair the
 * compiled cache, and a test process's own command line is `pest`. The same swap UncachedMethodSecurityTest
 * makes for the strict-method-security stand-down, for the same reason.
 *
 * @template TReturn
 *
 * @param  list<string>  $argv
 * @param  Closure(): TReturn  $body
 * @return TReturn
 */
function withStaleArgv(array $argv, Closure $body): mixed
{
    $original = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = $argv;

    try {
        return $body();
    } finally {
        $original === null ? array_key_exists('argv', $_SERVER) && ($_SERVER['argv'] = []) : $_SERVER['argv'] = $original;
    }
}

/**
 * Every class named by the compiled component manifest in $cache.
 *
 * @return list<string>
 */
function staleManifestClasses(string $cache): array
{
    return array_map(
        static fn (ComponentDescriptor $c): string => $c->class,
        ComponentManifest::load($cache.'/'.FireflyCachePaths::COMPONENT)->components,
    );
}

/**
 * Boots the stale app and runs the real CacheCommand against it.
 *
 * @return array{0: int, 1: string}
 */
function runStaleCacheCommand(Application $app): array
{
    $command = new CacheCommand;
    $command->setLaravel($app);

    $output = new BufferedOutput;
    $exit = $command->run(new ArrayInput([]), $output);

    return [$exit, $output->fetch()];
}

beforeEach(function () {
    StaleApp::restoreStaleCache();
});

it('recovers from a manifest naming a deleted class, and names the entry it skipped', function () {
    ['src' => $src, 'cache' => $cache] = StaleApp::prepare();

    // The precondition the whole test rests on: the compiled manifest still names the class, and the class
    // really is gone from this process — not stubbed absent, deleted from the tree it was compiled from.
    expect(class_exists(StaleApp::GHOST))->toBeFalse()
        ->and(staleManifestClasses($cache))->toContain(StaleApp::GHOST);

    [$exit, $rendered] = withStaleArgv(['artisan', 'firefly:cache'], function () use ($src, $cache): array {
        // Booting at all is half the assertion: before this change the boot below threw
        // BindingResolutionException from EagerSingletonsPass and the command never ran.
        $app = fireflyApplication(config: staleAppConfig($src, $cache), needs: ['cache']);

        return runStaleCacheCommand($app);
    });

    // One command, exit 0, and a line that names the file the developer deleted.
    expect($exit)->toBe(0)
        ->and($rendered)->toContain('skipped 1 stale manifest entry')
        ->and($rendered)->toContain(StaleApp::GHOST);

    // …and the manifest it wrote no longer mentions it, so the second run is an ordinary clean one.
    expect(staleManifestClasses($cache))
        ->not->toContain(StaleApp::GHOST)
        ->toContain(StaleApp::SURVIVOR);
});

it('says nothing about stale entries once the manifest is clean', function () {
    ['src' => $src, 'cache' => $cache] = StaleApp::prepare();

    $rendered = withStaleArgv(['artisan', 'firefly:cache'], function () use ($src, $cache): string {
        $app = fireflyApplication(config: staleAppConfig($src, $cache), needs: ['cache']);
        runStaleCacheCommand($app);

        // A SECOND boot, now over the manifest the first run wrote.
        $second = fireflyApplication(config: staleAppConfig($src, $cache), needs: ['cache']);

        /** @var string */
        return runStaleCacheCommand($second)[1];
    });

    expect($rendered)->toContain('wrote')
        ->and($rendered)->not->toContain('skipped');
});

/*
 | THE CONTROL, and the reason the skip is scoped to the two commands that repair the cache.
 |
 | A class that has gone missing in a process that is about to serve traffic is not a stale cache: it is a
 | broken deployment — a truncated artifact, a classmap built from a different tree — and dropping the
 | definition there would hand the application an interface quietly rebound to whichever implementation
 | survived, with nothing said. A wrong answer nobody is told about is far worse than the boot failure this
 | change removes, so under every other command the boot fails exactly as loudly as it always did.
 */
it('still fails loudly when the same stale manifest is booted under any other command', function () {
    ['src' => $src, 'cache' => $cache] = StaleApp::prepare();

    withStaleArgv(['artisan', 'migrate'], function () use ($src, $cache): void {
        expect(fn () => fireflyApplication(config: staleAppConfig($src, $cache), needs: ['cache']))
            ->toThrow(BindingResolutionException::class, 'Target class ['.StaleApp::GHOST.'] does not exist.');
    });
});

/*
 | The other half of the escape hatch. EagerSingletonsPass's docblock has named BOTH firefly:cache and
 | firefly:clear as the commands a stale manifest must not be able to block, and until AppScan grew
 | repairing() the claim was only true of one of them: firefly:clear boots the application too, so a
 | manifest naming a deleted class stopped the command whose entire job is to throw that manifest away.
 */
it('lets firefly:clear boot past the same stale manifest and remove the cache', function () {
    ['src' => $src, 'cache' => $cache] = StaleApp::prepare();

    $exit = withStaleArgv(['artisan', 'firefly:clear'], function () use ($src, $cache): int {
        $app = fireflyApplication(config: staleAppConfig($src, $cache), needs: ['cache']);

        $command = new ClearCommand;
        $command->setLaravel($app);

        return $command->run(new ArrayInput([]), new BufferedOutput);
    });

    expect($exit)->toBe(0)
        ->and(is_dir($cache))->toBeFalse();
});
