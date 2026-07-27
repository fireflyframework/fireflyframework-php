<?php

declare(strict_types=1);

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Web\Route\RouteManifest;

/** @return array<string,string> */
function fixturesPsr4(): array
{
    return ['Firefly\\Cli\\Tests\\Fixtures\\App\\' => __DIR__.'/../Fixtures/App'];
}

it('emits every app manifest data file with a var_export array shape', function () {
    $dir = sys_get_temp_dir().'/firefly-cache-'.bin2hex(random_bytes(6));

    $report = (new ManifestCacheWriter)->writeManifests(fixturesPsr4(), $dir);

    foreach ([
        FireflyCachePaths::COMPONENT, FireflyCachePaths::CONTEXT, FireflyCachePaths::CONFIG_PROPERTIES,
        FireflyCachePaths::ROUTES, FireflyCachePaths::CONSTRAINTS, FireflyCachePaths::HANDLERS,
        FireflyCachePaths::EVENT_LISTENERS, FireflyCachePaths::MESSAGE_LISTENERS, FireflyCachePaths::SCHEDULED,
        FireflyCachePaths::SECURITY_METHODS, FireflyCachePaths::TRANSACTIONAL,
    ] as $basename) {
        $path = $dir.'/'.$basename;
        expect(is_file($path))->toBeTrue("expected $basename to be emitted")
            ->and(require $path)->toBeArray();
    }

    expect($report->files)->toContain($dir.'/'.FireflyCachePaths::ROUTES);
});

it('round-trips the emitted manifests back into populated objects', function () {
    $dir = sys_get_temp_dir().'/firefly-cache-'.bin2hex(random_bytes(6));
    (new ManifestCacheWriter)->writeManifests(fixturesPsr4(), $dir);

    $routes = RouteManifest::load($dir.'/'.FireflyCachePaths::ROUTES);
    $handlers = HandlerManifest::load($dir.'/'.FireflyCachePaths::HANDLERS);
    $listeners = EventListenerManifest::load($dir.'/'.FireflyCachePaths::EVENT_LISTENERS);

    expect($routes)->toBeInstanceOf(RouteManifest::class)
        ->and($handlers)->toBeInstanceOf(HandlerManifest::class)
        ->and($listeners)->toBeInstanceOf(EventListenerManifest::class);
});
