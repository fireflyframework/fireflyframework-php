<?php

declare(strict_types=1);

use Firefly\Web\Error\SourcePaths;

/**
 * The shortening the whole debug page rests on.
 *
 * Tested here rather than through a rendered trace because the interesting cases are precisely the ones a
 * trace cannot produce on demand: a base path that names the same directory by a different route, a frame
 * under no root at all, a project whose own directory name contains the word vendor. Each is a string
 * problem with a string answer, and the page's height is the consequence.
 */
it('strips the literal base path, which is the cheap and common case', function () {
    $roots = ['/srv/app/'];

    expect(SourcePaths::shorten('/srv/app/app/Http/OrderController.php', $roots))->toBe('app/Http/OrderController.php')
        ->and(SourcePaths::shorten('/srv/app/vendor/laravel/framework/src/Illuminate/Routing/Route.php', $roots))
        ->toBe('vendor/laravel/framework/src/Illuminate/Routing/Route.php');
});

it('cuts at the LAST vendor segment when no root matches at all', function () {
    // The answer that needs nothing: from `vendor/` on, a path IS the dependency's identity. strrpos, not
    // strpos, so a project installed under a directory whose own name ends in `vendor` is cut at the right
    // one — and so is a package that vendors its own dependencies.
    expect(SourcePaths::shorten('/opt/releases/2026-09-23/vendor/psr/log/src/LoggerInterface.php', []))
        ->toBe('vendor/psr/log/src/LoggerInterface.php')
        ->and(SourcePaths::shorten('/opt/my-vendor/app/vendor/acme/tool/src/Run.php', []))
        ->toBe('vendor/acme/tool/src/Run.php');
});

it('leaves a path it cannot place alone rather than mangling it', function () {
    expect(SourcePaths::shorten('/usr/lib/php/something.php', ['/srv/app/']))->toBe('/usr/lib/php/something.php')
        ->and(SourcePaths::shorten('', ['/srv/app/']))->toBe('');
});

it('never treats the filesystem root as a root, which would strip one character off every path', function () {
    expect(SourcePaths::shorten('/usr/lib/php/x.php', ['/']))->toBe('/usr/lib/php/x.php');
});

it('prefers the most specific root, so a nested project is not described in terms of the one around it', function () {
    $roots = SourcePaths::roots(new RuntimeException('x'), '');

    expect(SourcePaths::shorten('/repo/packages/web/src/X.php', ['/repo/', '/repo/packages/web/']))
        ->toBe('src/X.php')
        // roots() itself returns longest-first, which is what makes the line above the behaviour and not an
        // accident of the order a caller happened to build the list in.
        ->and($roots)->toBe(array_values(array_unique($roots)));

    $ordered = SourcePaths::roots(new RuntimeException('x'), __DIR__);
    $lengths = array_map(strlen(...), $ordered);
    $descending = $lengths;
    rsort($descending);

    expect($lengths)->toBe($descending);
});

it('normalises the base path through realpath, so a symlinked deployment still shortens', function () {
    // THE MEASURED BUG. /var/www -> /opt/releases/<sha> is how every zero-downtime deploy works, and PHP
    // reports __FILE__ through the resolved path while the application's base path is the symlink. A literal
    // prefix misses on every single frame, and the page stops shortening anything at all.
    $real = sys_get_temp_dir().'/firefly-src-'.bin2hex(random_bytes(5));
    $link = sys_get_temp_dir().'/firefly-link-'.bin2hex(random_bytes(5));
    mkdir($real.'/app', 0o777, true);
    touch($real.'/app/Service.php');
    symlink($real, $link);

    // The FRAME side of the scenario has to be the resolved path, because that is what PHP reports for a
    // file — and on macOS the temp dir is itself reached through a symlink (/var -> /private/var), so the
    // name we just built is not it. Resolving here keeps the test about the deployment symlink under test
    // instead of about the platform's own.
    $real = realpath($real) ?: $real;

    $roots = SourcePaths::roots(new RuntimeException('x'), $link);

    expect(SourcePaths::shorten($real.'/app/Service.php', $roots))->toBe('app/Service.php')
        ->and(SourcePaths::shorten($link.'/app/Service.php', $roots))->toBe('app/Service.php');

    unlink($link);
    unlink($real.'/app/Service.php');
    rmdir($real.'/app');
    rmdir($real);
});

it('learns the project root from the trace itself when the base path is of no use', function () {
    // The harness case, and the one that produced the screenshots: the application's base path is a vendored
    // Testbench app while every frame names the repository it was booted from. Everything before a
    // `/vendor/` segment IS a Composer project root, whatever the application believes — so the trace
    // describes its own roots, with no configuration, no syscall and no symlink resolution.
    $roots = SourcePaths::roots(new RuntimeException('boom'), '/nowhere-at-all');
    $repository = dirname(__DIR__, 4).'/';

    expect($roots)->toContain($repository)
        ->and(SourcePaths::shorten($repository.'app/Orders/OrderService.php', $roots))
        ->toBe('app/Orders/OrderService.php');
});

it('collects roots from the previous chain too, because that is where the real cause usually is', function () {
    $cause = new RuntimeException('the inner cause');
    $roots = SourcePaths::roots(new LogicException('outer', 0, $cause), '');

    expect($roots)->not->toBeEmpty()
        ->and(SourcePaths::shorten($cause->getFile(), $roots))->not->toStartWith('/');
});
