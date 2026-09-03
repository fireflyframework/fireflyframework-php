<?php

declare(strict_types=1);

use Firefly\Installer\NewCommand;
use Firefly\Installer\SymfonyProcessRunner;
use Firefly\Installer\Tests\Support\Skeleton;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * Drives the REAL `firefly new` end-to-end against LOCAL path repos for the whole firefly family
 * (Packagist deps from the host cache), mirroring packages/cli/tests/Skeleton/CreateProjectOfflineTest.php.
 * GATING: grouped `installer` — EXCLUDED from the default gate. Run with `vendor/bin/pest --group=installer`.
 */
it('scaffolds a booting app via the real installer over local path repos', function () {
    $composer = trim((string) shell_exec('command -v composer')) ?: null;
    if ($composer === null) {
        $this->markTestSkipped('composer binary not available.');
    }

    $mono = dirname(__DIR__, 3); // packages/installer/tests -> repo root
    $hostCacheDir = trim((string) shell_exec($composer.' config --global cache-dir 2>/dev/null'));
    if ($hostCacheDir === '' || ! is_dir($hostCacheDir)) {
        $this->markTestSkipped('no host Composer cache available.');
    }

    $work = sys_get_temp_dir().'/firefly-new-'.bin2hex(random_bytes(6));
    $home = $work.'/composer-home';
    @mkdir($home, 0o755, true);
    file_put_contents($home.'/config.json', (string) json_encode([
        'repositories' => [
            'firefly-pkgs' => ['type' => 'path', 'url' => $mono.'/packages/*', 'options' => ['symlink' => false]],
            'firefly-skeleton' => ['type' => 'path', 'url' => $mono.'/skeleton', 'options' => ['symlink' => false]],
        ],
        'minimum-stability' => 'dev',
    ], JSON_PRETTY_PRINT));

    // Symfony 8's Process::getDefaultEnv() intersects getenv() with $_SERVER keys, so a bare putenv()
    // (with no matching $_SERVER key) is silently dropped from the child environment. Set both so the
    // composer subprocess actually sees the isolated COMPOSER_HOME/COMPOSER_CACHE_DIR.
    putenv("COMPOSER_HOME={$home}");
    putenv("COMPOSER_CACHE_DIR={$hostCacheDir}");
    $_SERVER['COMPOSER_HOME'] = $home;
    $_SERVER['COMPOSER_CACHE_DIR'] = $hostCacheDir;

    $command = new NewCommand(new SymfonyProcessRunner(new BufferedOutput));
    (new Application)->addCommand($command);
    $tester = new CommandTester($command);

    try {
        // interactive:false — `new` prompts for the archetype and the capabilities when neither is
        // flagged, and CommandTester is interactive by default with no input stream to answer from.
        $tester->execute(['name' => $work.'/my-app', '--dev' => true, '--no-git' => true], ['interactive' => false]);
        expect(is_file($work.'/my-app/artisan'))->toBeTrue()
            ->and(is_file($work.'/my-app/bootstrap/cache/firefly/routes.php'))->toBeTrue()
            // the default archetype shaped a real create-project result, not just a fixture
            ->and(Skeleton::stamp($work.'/my-app'))->toBe(['archetype' => 'web', 'capabilities' => []]);
    } finally {
        (new Process(['rm', '-rf', $work]))->run();
        putenv('COMPOSER_HOME');
        putenv('COMPOSER_CACHE_DIR');
        unset($_SERVER['COMPOSER_HOME'], $_SERVER['COMPOSER_CACHE_DIR']);
    }
})->group('installer');
