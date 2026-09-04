<?php

declare(strict_types=1);

use Firefly\Installer\Filesystem;

function tempDir(): string
{
    $dir = sys_get_temp_dir().'/ffs-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    return $dir;
}

it('reports emptiness without counting . and ..', function () {
    $dir = tempDir();

    try {
        expect(Filesystem::directoryIsNotEmpty($dir))->toBeFalse();
        file_put_contents($dir.'/.hidden', 'x');
        expect(Filesystem::directoryIsNotEmpty($dir))->toBeTrue();
        expect(Filesystem::directoryIsNotEmpty($dir.'/does-not-exist'))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('empties a tree without removing the directory itself', function () {
    $dir = tempDir();
    mkdir($dir.'/a/b/c', 0o755, true);
    file_put_contents($dir.'/a/b/c/deep.txt', 'x');
    file_put_contents($dir.'/.dotfile', 'x');

    try {
        expect(Filesystem::emptyDirectory($dir))->toBeTrue()
            ->and(is_dir($dir))->toBeTrue()
            ->and(scandir($dir))->toBe(['.', '..']);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

/**
 * The one that would have hurt. RecursiveDirectoryIterator reports a symlinked directory as a directory,
 * so an emptyDirectory() that branched on isDir() alone would call rmdir() on the LINK — and, worse, a
 * CHILD_FIRST walk that descended through it would delete the target's contents first. `firefly new . --force`
 * in a directory holding a `current -> ~/work` link would have taken ~/work with it.
 */
it('unlinks a symlinked directory instead of following it', function () {
    $dir = tempDir();
    $victim = tempDir();
    file_put_contents($victim.'/precious.txt', 'x');
    symlink($victim, $dir.'/link');

    try {
        expect(Filesystem::emptyDirectory($dir))->toBeTrue()
            ->and(scandir($dir))->toBe(['.', '..'])
            ->and(is_file($victim.'/precious.txt'))->toBeTrue();
    } finally {
        exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg($victim));
    }
});

it('treats the filesystem root and the home directory as protected', function () {
    $dir = tempDir();
    $home = getenv('HOME');

    try {
        expect(Filesystem::isProtectedPath('/'))->toBeTrue()
            ->and(Filesystem::isProtectedPath($dir))->toBeFalse();

        putenv("HOME={$dir}");
        expect(Filesystem::isProtectedPath($dir))->toBeTrue();

        // A path that does not exist yet cannot be destroyed, so it is never "protected".
        expect(Filesystem::isProtectedPath($dir.'/not-created'))->toBeFalse();
    } finally {
        is_string($home) ? putenv("HOME={$home}") : putenv('HOME');
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('prunes directories the prune emptied, and stops at the root', function () {
    $root = tempDir();
    mkdir($root.'/resources/views', 0o755, true);
    file_put_contents($root.'/resources/views/welcome.blade.php', 'x');

    try {
        Filesystem::delete($root.'/resources/views/welcome.blade.php');
        Filesystem::pruneEmptyDirectories($root, $root.'/resources/views');

        expect(is_dir($root.'/resources'))->toBeFalse()
            ->and(is_dir($root))->toBeTrue(); // the project root is never a candidate
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('stops pruning at the first directory that still holds something', function () {
    $root = tempDir();
    mkdir($root.'/app/Http', 0o755, true);
    file_put_contents($root.'/app/GreetingService.php', 'x');
    file_put_contents($root.'/app/Http/WelcomeController.php', 'x');

    try {
        Filesystem::delete($root.'/app/Http/WelcomeController.php');
        Filesystem::pruneEmptyDirectories($root, $root.'/app/Http');

        expect(is_dir($root.'/app/Http'))->toBeFalse()
            ->and(is_file($root.'/app/GreetingService.php'))->toBeTrue()
            ->and(is_dir($root.'/app'))->toBeTrue();
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});
