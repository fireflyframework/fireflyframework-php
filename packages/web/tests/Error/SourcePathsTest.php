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
        ->and(SourcePaths::shorten($link.'/app/Service.php', $roots))->toBe('app/Service.php')
        // The realpath-derived root is spelled with both separators, exactly like the literal one. Asserted
        // here because this is the only test that reaches the realpath branch at all, and that branch had
        // the same hardcoded forward slash: fixing the literal prefix while leaving its twin behind would
        // have left the symlinked deployment broken on Windows and nothing would have said so.
        ->and($roots)->toContain($real.'\\');

    unlink($link);
    unlink($real.'/app/Service.php');
    rmdir($real.'/app');
    rmdir($real);
});

it('strips a Windows base path, whose separator is the other one', function () {
    // THE REGRESSION THIS PINS. `Application::basePath()` answers `C:\srv\app` on Windows and PHP reports
    // trace files as `C:\srv\app\Http\Controllers\OrderController.php`, so a root built by appending a
    // forward slash is a string no path on that machine can start with — the literal-prefix answer and the
    // realpath()-normalised one were both dead by construction. The three lines of `str_starts_with` this
    // class replaced returned `Http\Controllers\OrderController.php` here, so this is behaviour owed.
    //
    // And it failed INVISIBLY: the `\vendor\`-derived root is spelled by the frame itself, so it survived
    // and shortened every dimmed dependency row, leaving precisely the APPLICATION rows — the ones that
    // carry excerpts — printing absolute paths and wrapping. CI is ubuntu-only; only an assertion written
    // in backslashes can see it, and before this file had one there was not a single backslash path in it.
    $roots = SourcePaths::roots(new RuntimeException('x'), 'C:\\srv\\app');

    expect($roots)->toContain('C:\\srv\\app\\')
        ->and(SourcePaths::shorten('C:\\srv\\app\\Http\\Controllers\\OrderController.php', $roots))
        ->toBe('Http\\Controllers\\OrderController.php')
        ->and(SourcePaths::shorten('C:\\srv\\app\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Route.php', $roots))
        ->toBe('vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Route.php');

    // The other spelling is inert rather than merely harmless: a '/'-separated frame never matches a
    // '\'-suffixed root and vice versa, so a POSIX base path shortens exactly as it did before.
    expect(SourcePaths::shorten('/srv/app/app/Http/OrderController.php', SourcePaths::roots(new RuntimeException('x'), '/srv/app')))
        ->toBe('app/Http/OrderController.php');
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

it('derives a root from the OUTERMOST vendor segment, so a nested vendor cannot pose as the project', function () {
    // A dependency that ships its own `vendor/` is ordinary — this repository vendors one, and
    // vendor/symplify/monorepo-builder/vendor exists in its tree right now. Reading the LAST vendor segment
    // made `<project>/vendor/acme/tool/` a root, and because shorten() deliberately prefers the LONGEST
    // matching root, that fake root BEAT the real project root: the package's own `src/Builder.php` printed
    // as a bare `src/Builder.php`, indistinguishable from the reader's own code, while the row stayed dimmed
    // as vendor and package() answered null. It also removed a prefix no other row shares, which is the one
    // promise this class makes — a reader who needs the absolute name puts the project root back.
    $project = sys_get_temp_dir().'/firefly-nested-'.bin2hex(random_bytes(5));
    mkdir($project.'/vendor/acme/tool/src', 0o777, true);
    mkdir($project.'/vendor/acme/tool/vendor/psr/log/src', 0o777, true);

    // macOS reaches its temp directory through a symlink, and PHP reports __FILE__ resolved — so the names
    // the frames will carry are the resolved ones, not the ones just built.
    $project = realpath($project) ?: $project;
    $builderFile = $project.'/vendor/acme/tool/src/Builder.php';
    $innerFile = $project.'/vendor/acme/tool/vendor/psr/log/src/Inner.php';

    // Closures rather than functions, so the files can be required without ever colliding on a name, and a
    // REAL throwable whose getFile() is the nested file and whose first trace frame is the outer one. The
    // shape has to come out of PHP itself: roots() reads a Throwable, and a hand-built list of strings would
    // be testing the test.
    file_put_contents($innerFile, "<?php return static function (): void { throw new RuntimeException('nested'); };\n");
    file_put_contents($builderFile, "<?php return static function (callable \$inner): void { \$inner(); };\n");

    $inner = require $innerFile;
    $builder = require $builderFile;
    $thrown = null;

    if (! is_callable($inner) || ! is_callable($builder)) {
        throw new LogicException('the nested-vendor fixture did not return closures');
    }

    try {
        $builder($inner);
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    // A guard rather than decoration: every assertion below reads the throwable the fixture produced, and a
    // fixture that quietly stopped throwing would turn all of them into passes.
    if (! $thrown instanceof RuntimeException) {
        throw new LogicException('the nested-vendor fixture did not throw');
    }

    expect($thrown->getFile())->toBe($innerFile);

    $roots = SourcePaths::roots($thrown, '');

    expect($roots)->toContain($project.'/')
        ->and($roots)->not->toContain($project.'/vendor/acme/tool/')
        // Both rows keep the `vendor/` prefix that tells the reader whose code they are looking at, and the
        // inner one keeps the outer package in front of it, so the nesting is visible rather than erased.
        ->and(SourcePaths::shorten($builderFile, $roots))->toBe('vendor/acme/tool/src/Builder.php')
        ->and(SourcePaths::shorten($innerFile, $roots))->toBe('vendor/acme/tool/vendor/psr/log/src/Inner.php');

    unlink($innerFile);
    unlink($builderFile);
    foreach ([
        $project.'/vendor/acme/tool/vendor/psr/log/src',
        $project.'/vendor/acme/tool/vendor/psr/log',
        $project.'/vendor/acme/tool/vendor/psr',
        $project.'/vendor/acme/tool/vendor',
        $project.'/vendor/acme/tool/src',
        $project.'/vendor/acme/tool',
        $project.'/vendor/acme',
        $project.'/vendor',
        $project,
    ] as $directory) {
        rmdir($directory);
    }
});

it('collects roots from the previous chain too, because that is where the real cause usually is', function () {
    $cause = new RuntimeException('the inner cause');
    $roots = SourcePaths::roots(new LogicException('outer', 0, $cause), '');

    expect($roots)->not->toBeEmpty()
        ->and(SourcePaths::shorten($cause->getFile(), $roots))->not->toStartWith('/');
});
