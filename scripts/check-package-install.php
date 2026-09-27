<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$published = in_array('--published', $argv, true);
$version = $published ? getenv('RELEASE_TAG') : '26.9.99';
$reference = $published ? getenv('RELEASE_SHA') : null;
if ($published && (! is_string($version) || ! preg_match('/^v\d{2}\.\d{2}\.\d+$/', $version) || ! is_string($reference) || ! preg_match('/^[a-f0-9]{40}$/', $reference))) {
    throw new InvalidArgumentException('Published verification needs RELEASE_TAG=vYY.MM.Patch and RELEASE_SHA=<commit>.');
}
$work = sys_get_temp_dir().'/larafly-package-'.bin2hex(random_bytes(6));
mkdir($work, 0755, true);

function runPackageCommand(array $command, string $directory): string
{
    $process = new Process($command, $directory, ['COMPOSER_NO_INTERACTION' => '1'], timeout: 600);
    $process->mustRun();

    return $process->getOutput();
}

function packageAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function writePackageJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}

try {
    $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    packageAssert($manifest['name'] === 'fireflyframework/larafly', 'The repository root must publish fireflyframework/larafly.');
    if (! $published) {
        runPackageCommand(['composer', 'archive', '--format=zip', '--dir='.$work, '--file=firefly'], $root);
        $manifest['version'] = $version;
        $manifest['dist'] = ['type' => 'zip', 'url' => $work.'/firefly.zip'];
        unset($manifest['source']);
    }

    // Only this distribution supplies firefly/*; Packagist is used for third-party dependencies.
    $repositories = $published ? [] : [
        ['type' => 'package', 'package' => $manifest],
        ['type' => 'composer', 'url' => 'https://repo.packagist.org', 'exclude' => ['firefly/*', 'fireflyframework/larafly']],
        ['packagist.org' => false],
    ];
    $app = $work.'/app';
    mkdir($app);
    $copy = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/skeleton', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($copy as $entry) {
        $target = $app.'/'.substr($entry->getPathname(), strlen($root.'/skeleton/'));
        $entry->isDir() ? mkdir($target, 0755, true) : copy($entry->getPathname(), $target);
    }
    $consumer = json_decode(file_get_contents($app.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $consumer['repositories'] = $repositories;
    $consumer['require']['fireflyframework/larafly'] = $version;
    // Every former component must resolve to the same installed library.
    foreach (array_keys($manifest['replace']) as $name) {
        $consumer['require'][$name] = $version;
    }
    unset($consumer['require-dev'], $consumer['minimum-stability']);
    writePackageJson($app.'/composer.json', $consumer);
    runPackageCommand(['composer', 'update', '--no-dev', '--prefer-dist', '--no-scripts', '--no-progress'], $app);
    $installed = json_decode(file_get_contents($app.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR)['packages'];
    $names = array_column($installed, 'name');
    packageAssert(in_array('fireflyframework/larafly', $names, true), 'The root library was not installed.');
    packageAssert(array_filter($names, fn ($name) => str_starts_with($name, 'firefly/')) === [], 'A component was installed separately.');
    if ($published) {
        $library = array_values(array_filter($installed, fn ($package) => $package['name'] === 'fireflyframework/larafly'))[0];
        packageAssert(($library['source']['reference'] ?? null) === $reference, 'Packagist installed a different commit than the release tag.');
    }
    foreach (['orchestra/testbench', 'pestphp/pest', 'phpunit/phpunit'] as $dev) {
        packageAssert(! in_array($dev, $names, true), $dev.' leaked into a production install.');
    }
    packageAssert(! is_link($app.'/vendor/fireflyframework/larafly'), 'Consumer must use copied sources.');
    copy($app.'/.env.example', $app.'/.env');
    touch($app.'/database/database.sqlite');
    foreach ([['package:discover'], ['key:generate'], ['migrate', '--force'], ['firefly:cache']] as $arguments) {
        runPackageCommand([PHP_BINARY, 'artisan', ...$arguments], $app);
    }
    runPackageCommand([PHP_BINARY, 'vendor/bin/firefly', '--help'], $app);
    runPackageCommand([PHP_BINARY, 'vendor/fireflyframework/larafly/packages/installer/bin/firefly', '--help'], $app);
    packageAssert(! is_dir($app.'/vendor/fireflyframework/larafly/vendor'), 'Development vendor directory leaked into the archive.');
    packageAssert(! is_dir($app.'/vendor/fireflyframework/larafly/tests'), 'Repository tests leaked into the archive.');
    file_put_contents($app.'/probe.php', <<<'PROBE'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('/greetings/Package'));
if ($response->getStatusCode() !== 200 || ! str_contains($response->getContent(), 'Package')) {
    throw new RuntimeException('Installed application route failed: '.$response->getContent());
}
if (Illuminate\Support\Facades\Schema::hasTable(Firefly\Eda\Postgres\Outbox\OutboxSchema::TABLE)) {
    throw new RuntimeException('An unselected adapter registered its migrations.');
}
if (class_exists('Lumen\\Domain\\Money') || class_exists('Firefly\\Tests\\Browser\\Support\\DiscoveredProviders')) {
    throw new RuntimeException('Repository test autoloads leaked into a consumer.');
}
if (! class_exists(Firefly\Eda\Kafka\EdaKafkaServiceProvider::class) || ! function_exists('fireflyApplication')) {
    throw new RuntimeException('Component code or autoload.files is missing.');
}
echo "Installed application boots and serves its compiled route.\n";
PROBE);
    echo runPackageCommand([PHP_BINARY, 'probe.php'], $app);
    $home = $work.'/composer-home';
    mkdir($home);
    writePackageJson($home.'/config.json', ['repositories' => $repositories]);
    $installer = new Process([PHP_BINARY, 'vendor/bin/firefly', 'new', $work.'/generated', '--no-git', '--no-interaction'], $app, ['COMPOSER_HOME' => $home], timeout: 600);
    $installer->mustRun();
    packageAssert(is_file($work.'/generated/bootstrap/cache/firefly/routes.php'), 'Bundled installer did not create a compiled app.');
    packageAssert(is_file($work.'/generated/tests/TestCase.php'), 'Bundled skeleton lost its application tests.');

    $lumen = $work.'/lumen';
    mkdir($lumen);
    $sample = json_decode(file_get_contents($root.'/samples/lumen/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $sample['repositories'] = [
        ['type' => 'path', 'url' => $root, 'options' => ['symlink' => false, 'versions' => ['fireflyframework/larafly' => '26.9.99']]],
        ['type' => 'composer', 'url' => 'https://repo.packagist.org', 'exclude' => ['firefly/*', 'fireflyframework/larafly']],
        ['packagist.org' => false],
    ];
    // Source/test namespaces in the sample remain relative to the sample project.
    $sample['autoload']['psr-4']['Lumen\\'] = $root.'/samples/lumen/src/';
    writePackageJson($lumen.'/composer.json', $sample);
    runPackageCommand(['composer', 'update', '--no-dev', '--prefer-dist', '--no-scripts', '--no-progress'], $lumen);
    $sampleInstalled = json_decode(file_get_contents($lumen.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR)['packages'];
    packageAssert(array_filter(array_column($sampleInstalled, 'name'), fn ($name) => str_starts_with($name, 'firefly/')) === [], 'Lumen installed a split package.');
    packageAssert(! is_link($lumen.'/vendor/fireflyframework/larafly'), 'Lumen must exercise copied path sources.');
    foreach (['vendor', 'node_modules', '.venv-docs', 'tests'] as $developmentDirectory) {
        packageAssert(! is_dir($lumen.'/vendor/fireflyframework/larafly/'.$developmentDirectory), 'Copied library includes development directory '.$developmentDirectory.'.');
    }
    runPackageCommand([PHP_BINARY, '-r', 'require "vendor/autoload.php"; if (! class_exists("Lumen\\Domain\\Money")) { exit(1); }'], $lumen);

    $consumer['require']['firefly/eda-kafka'] = '^27.0';
    writePackageJson($app.'/composer.json', $consumer);
    $conflict = new Process(['composer', 'update', '--dry-run', '--no-dev', '--no-scripts'], $app, timeout: 120);
    $conflict->run();
    packageAssert(! $conflict->isSuccessful() && str_contains($conflict->getErrorOutput(), 'firefly/eda-kafka'), 'self.version accepted an incompatible component version.');
    echo "Package distribution, component replacement, production dependencies and version conflict verified.\n";
    echo "Consumer retained at {$app}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\nConsumer retained at {$work}\n");
    exit(1);
}
