<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Exercises the firefly/skeleton create-project template end-to-end: `composer create-project firefly/skeleton`
 * against LOCAL path repos for the whole firefly family (no GitHub/remote for firefly/*), asserting the created
 * app is genuinely runnable (real `artisan`) and that the `post-create-project-cmd` `firefly:cache` step emitted
 * the compiled manifests.
 *
 * SCOPE: only firefly/* packages are path repos here. laravel/framework + its Symfony transitive deps come from
 * Packagist, so COMPOSER_CACHE_DIR is pointed at the HOST machine's real Composer cache (resolved before HOME is
 * overridden) so those deps resolve from cache instead of forcing a fresh network round-trip, while COMPOSER_HOME
 * stays isolated for the path-repo config.
 *
 * GATING: grouped `createproject` — EXCLUDED from the default gate (it shells out to a full composer install and
 * is slow). Run on demand with `vendor/bin/pest --group=createproject`.
 */
it('creates a booting, cached app via local firefly path repos (Packagist deps from host cache)', function () {
    $composer = trim((string) shell_exec('command -v composer')) ?: null;
    if ($composer === null) {
        $this->markTestSkipped('composer binary not available.');
    }

    $mono = dirname(__DIR__, 4); // packages/cli/tests/Skeleton -> repo root

    // Resolve the REAL host Composer cache dir BEFORE HOME is overridden below, so Packagist deps
    // (laravel/framework + Symfony) resolve from the host's existing cache instead of a network fetch.
    // `composer config --global cache-dir` is authoritative + platform-independent (macOS keeps its cache
    // under ~/Library/Caches, not ~/.composer); fall back to the well-known candidates if that yields nothing.
    $realHome = getenv('HOME') ?: '';
    $hostCacheDir = trim((string) shell_exec($composer.' config --global cache-dir 2>/dev/null'));
    if ($hostCacheDir === '' || ! is_dir($hostCacheDir)) {
        $hostCacheDir = '';
        foreach ([
            $realHome.'/.composer/cache',
            $realHome.'/.cache/composer',
            $realHome.'/Library/Caches/composer',
        ] as $candidate) {
            if (is_dir($candidate)) {
                $hostCacheDir = $candidate;
                break;
            }
        }
    }
    if ($hostCacheDir === '') {
        $this->markTestSkipped('no host Composer cache available; refusing to force a full network resolve.');
    }

    $work = sys_get_temp_dir().'/firefly-cp-'.bin2hex(random_bytes(6));
    $home = $work.'/composer-home';
    @mkdir($home, 0o755, true);

    // A COMPOSER_HOME config with path repos for EVERY firefly package + the skeleton (globs supported).
    // symlink:false forces a real copy-install from the local path repos (no symlink into the monorepo).
    file_put_contents($home.'/config.json', (string) json_encode([
        'repositories' => [
            'firefly-pkgs' => ['type' => 'path', 'url' => $mono.'/packages/*', 'options' => ['symlink' => false]],
            'firefly-skeleton' => ['type' => 'path', 'url' => $mono.'/skeleton', 'options' => ['symlink' => false]],
        ],
        'minimum-stability' => 'dev',
    ], JSON_PRETTY_PRINT));

    $target = $work.'/my-app';
    $process = new Process(
        [$composer, 'create-project', 'firefly/skeleton', $target, '--stability=dev', '--no-interaction'],
        env: [
            'COMPOSER_HOME' => $home,
            'COMPOSER_CACHE_DIR' => $hostCacheDir,
            'HOME' => $work,
            'PATH' => (string) getenv('PATH'),
        ],
        timeout: 600,
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(is_file($target.'/artisan'))->toBeTrue()                                  // BLOCKER-2: runnable app
        ->and(is_dir($target.'/vendor/firefly/web'))->toBeTrue()                        // firefly family installed
        ->and(is_file($target.'/bootstrap/cache/firefly/component.php'))->toBeTrue()    // post-create firefly:cache ran
        ->and(is_file($target.'/bootstrap/cache/firefly/context.php'))->toBeTrue()
        ->and(is_file($target.'/bootstrap/cache/firefly/routes.php'))->toBeTrue();      // the sample #[RestController] compiled

    // teardown
    (new Process(['rm', '-rf', $work]))->run();
})->group('createproject');
