<?php

declare(strict_types=1);

use Firefly\Web\Error\ErrorFrame;

/**
 * A frame knows three things about its own path: where it is, what it is called, and whose code it is —
 * and the same two things about its call.
 *
 * They are separate accessors rather than one formatted string because the page needs them separately: the
 * DIRECTORY may be ellipsised when a row runs out of width, the FILE NAME never may — a row that ends in
 * `…Contro…` has stopped being information — and the PACKAGE is what turns ninety dim rows into "everything
 * here is laravel/framework". The call splits on the same rule and for the same reason: the QUALIFIER may
 * be clipped, the METHOD NAME may not, because it is what tells sixty `Illuminate\…` frames apart.
 */
it('splits a shortened path into a directory and a file name', function () {
    $frame = new ErrorFrame(
        file: '/srv/app/vendor/laravel/framework/src/Illuminate/Routing/Route.php',
        shortFile: 'vendor/laravel/framework/src/Illuminate/Routing/Route.php',
        line: 254,
        call: 'Illuminate\Routing\Route->run()',
        vendor: true,
    );

    expect($frame->dir())->toBe('vendor/laravel/framework/src/Illuminate/Routing/')
        ->and($frame->base())->toBe('Route.php')
        ->and($frame->package())->toBe('laravel/framework')
        ->and($frame->index)->toBe(0);
});

it('has no directory and no package for a bare name or an internal function', function () {
    $internal = new ErrorFrame(file: '', shortFile: '[internal function]', line: null, call: 'array_map()', vendor: true);

    expect($internal->dir())->toBe('')
        ->and($internal->base())->toBe('[internal function]')
        ->and($internal->package())->toBeNull();
});

it('reads the package from the LAST vendor segment, and only from a real one', function () {
    $nested = new ErrorFrame(
        file: '/srv/vendor/acme/tool/vendor/psr/log/src/LoggerInterface.php',
        shortFile: 'vendor/acme/tool/vendor/psr/log/src/LoggerInterface.php',
        line: 12,
        call: 'Psr\Log\LoggerInterface->error()',
        vendor: true,
    );

    // `my-vendor/` is a directory whose name merely ends in the word; it is not a Composer vendor dir, and
    // reading `x/y` out of it would label an application frame with a package that does not exist.
    $lookalike = new ErrorFrame(
        file: '/srv/my-vendor/x/y/Thing.php',
        shortFile: 'my-vendor/x/y/Thing.php',
        line: 3,
        call: 'y()',
        vendor: false,
    );

    expect($nested->package())->toBe('psr/log')
        ->and($lookalike->package())->toBeNull();
});

it('carries its position in the untrimmed stack, so a trimmed list still reads as a stack', function () {
    $frame = new ErrorFrame(file: '/a/b.php', shortFile: 'b.php', line: 1, call: 'b()', vendor: false, index: 37);

    expect($frame->index)->toBe(37);
});

it('treats a Windows separator as a separator', function () {
    $frame = new ErrorFrame(
        file: 'C:\\srv\\vendor\\laravel\\framework\\src\\Route.php',
        shortFile: 'vendor\\laravel\\framework\\src\\Route.php',
        line: 9,
        call: 'run()',
        vendor: true,
    );

    expect($frame->base())->toBe('Route.php')
        ->and($frame->dir())->toBe('vendor\\laravel\\framework\\src\\');
});

it('splits a call into a qualifier that may be clipped and a function name that never is', function () {
    $frame = static fn (string $call): ErrorFrame => new ErrorFrame(
        file: '/srv/app/x.php', shortFile: 'x.php', line: 1, call: $call, vendor: false,
    );

    expect($frame('Illuminate\Database\Eloquent\Builder->get()')->callQualifier())->toBe('Illuminate\Database\Eloquent\Builder')
        ->and($frame('Illuminate\Database\Eloquent\Builder->get()')->callFunction())->toBe('->get()')
        ->and($frame('Illuminate\Routing\Route::make()')->callQualifier())->toBe('Illuminate\Routing\Route')
        ->and($frame('Illuminate\Routing\Route::make()')->callFunction())->toBe('::make()')
        // A namespaced free function has no class, and its namespace is still the discardable half.
        ->and($frame('Illuminate\Support\collect()')->callQualifier())->toBe('Illuminate\Support')
        ->and($frame('Illuminate\Support\collect()')->callFunction())->toBe('\collect()');
});

it('leaves a call with no qualifier whole, and reads a closure descriptor as part of the function', function () {
    $frame = static fn (string $call): ErrorFrame => new ErrorFrame(
        file: '/srv/app/x.php', shortFile: 'x.php', line: 1, call: $call, vendor: false,
    );

    // The synthetic first frame, an internal function, and a bare closure: nothing to clip, so the whole
    // token is the function and the qualifier slot is not printed at all.
    expect($frame('throw')->callQualifier())->toBe('')
        ->and($frame('throw')->callFunction())->toBe('throw')
        ->and($frame('array_map()')->callQualifier())->toBe('')
        ->and($frame('array_map()')->callFunction())->toBe('array_map()')
        ->and($frame('{closure}')->callQualifier())->toBe('')
        ->and($frame('{closure}')->callFunction())->toBe('{closure}')
        // PHP 8.4 names a closure after the file it was written in, and on Windows that path carries
        // separators of its own. The cut is looked for before the first brace so the descriptor survives.
        ->and($frame('App\Jobs\Sync::{closure:C:\app\Jobs\Sync.php:31}()')->callQualifier())->toBe('App\Jobs\Sync')
        ->and($frame('App\Jobs\Sync::{closure:C:\app\Jobs\Sync.php:31}()')->callFunction())->toBe('::{closure:C:\app\Jobs\Sync.php:31}()');
});
