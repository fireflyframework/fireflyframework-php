<?php

declare(strict_types=1);

use Firefly\Context\Scan\AppScan;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/** @param array<string,mixed> $firefly */
function appScanContainer(array $firefly = []): Container
{
    $c = new Container;
    $c->instance('config', new Repository(['firefly' => $firefly]));

    return $c;
}

it('returns the configured psr-4 scan roots', function () {
    $roots = AppScan::paths(appScanContainer(['scan' => ['paths' => ['App\\' => '/srv/app']]]));

    expect($roots)->toBe(['App\\' => '/srv/app']);
});

it('returns no roots when firefly.scan.paths is unset, empty or malformed', function (mixed $paths) {
    expect(AppScan::paths(appScanContainer(['scan' => ['paths' => $paths]])))->toBe([]);
})->with([
    'unset' => [[]],
    'not an array' => ['App\\'],
    'non-string values' => [['App\\' => 123]],
    'empty prefix' => [['' => '/srv/app']],
]);

it('honours firefly.cache.path when resolving the cache dir', function () {
    $dir = AppScan::dir(appScanContainer(['cache' => ['path' => '/var/cache/firefly/']]));

    expect($dir)->toBe('/var/cache/firefly');
});

it('finds a compiled artifact only when the file actually exists', function () {
    $dir = sys_get_temp_dir().'/firefly-appscan-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o700, true);
    file_put_contents($dir.'/'.AppScan::ROUTES, "<?php return [];\n");

    $app = appScanContainer(['cache' => ['path' => $dir]]);

    expect(AppScan::cachedFile($app, AppScan::ROUTES))->toBe($dir.'/'.AppScan::ROUTES)
        ->and(AppScan::cachedFile($app, AppScan::HANDLERS))->toBeNull();

    unlink($dir.'/'.AppScan::ROUTES);
    rmdir($dir);
});

it('enumerates declared classes under a psr-4 root and ignores missing directories', function () {
    $classes = AppScan::classes(['Firefly\\Context\\Scan\\' => dirname(__DIR__, 2).'/src/Scan']);

    expect($classes)->toContain(AppScan::class)
        ->and(AppScan::classes(['Nope\\' => '/does/not/exist']))->toBe([]);
});

// The basenames are duplicated in Firefly\Cli\Cache\FireflyCachePaths because Context sits far below Cli in
// the layer graph. If the two ever drift, the compiled artifact firefly:cache writes stops being the one the
// capability packages look for, and every Category-B manifest silently falls back to a full reflection scan.
it('agrees with firefly/cli on every compiled artifact basename', function () {
    $cli = dirname(__DIR__, 3).'/cli/src/Cache/FireflyCachePaths.php';

    if (! is_file($cli)) {
        expect(true)->toBeTrue(); // firefly/cli not present in this install

        return;
    }

    $source = (string) file_get_contents($cli);

    foreach ([
        AppScan::COMPONENT, AppScan::CONTEXT, AppScan::CONFIG_PROPERTIES, AppScan::ROUTES,
        AppScan::EXCEPTION_HANDLERS, AppScan::CONSTRAINTS, AppScan::HANDLERS, AppScan::EVENT_LISTENERS,
        AppScan::MESSAGE_LISTENERS, AppScan::SCHEDULED, AppScan::SECURITY_METHODS, AppScan::TRANSACTIONAL,
        AppScan::PROXY_MAP,
    ] as $basename) {
        expect($source)->toContain("'{$basename}'");
    }
});

/*
 | `firefly:cache` is the command that WRITES the manifests, and it can only run by booting the very
 | application whose manifests it is about to replace. Trusting the manifests already on disk during that
 | boot makes the command unable to fix the thing it exists to fix: a component.php naming a class that
 | can no longer be autowired kills EagerSingletonsPass before the writer runs, and the only recovery is
 | deleting the cache directory by hand. While regenerating, the compiled artefacts are therefore ignored
 | and every capability falls back to its in-process scan — which is what produces the correct manifest.
 */
it('reports that it is regenerating only while firefly:cache is the running command', function (array $argv, bool $expected) {
    $original = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = $argv;

    try {
        expect(AppScan::regenerating())->toBe($expected);
    } finally {
        $original === null ? array_key_exists('argv', $_SERVER) && ($_SERVER['argv'] = []) : $_SERVER['argv'] = $original;
    }
})->with([
    'firefly:cache' => [['artisan', 'firefly:cache'], true],
    'with options first' => [['artisan', '--no-ansi', 'firefly:cache'], true],
    'another command' => [['artisan', 'migrate'], false],
    'a lookalike' => [['artisan', 'firefly:cache-clear'], false],
    'no command' => [['artisan'], false],
]);

it('ignores a compiled artefact that exists while firefly:cache is regenerating', function () {
    $dir = sys_get_temp_dir().'/firefly-appscan-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/'.AppScan::COMPONENT, '<?php return [];');

    $container = appScanContainer(['cache' => ['path' => $dir]]);
    $original = $_SERVER['argv'] ?? [];

    try {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        expect(AppScan::cachedFile($container, AppScan::COMPONENT))->toBe($dir.'/'.AppScan::COMPONENT);

        $_SERVER['argv'] = ['artisan', 'firefly:cache'];
        expect(AppScan::cachedFile($container, AppScan::COMPONENT))->toBeNull();
    } finally {
        $_SERVER['argv'] = $original;
        @unlink($dir.'/'.AppScan::COMPONENT);
        @rmdir($dir);
    }
});
