<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Firefly\Web\Http\UploadedFile;
use Illuminate\Http\UploadedFile as IlluminateUploadedFile;

it('exposes metadata and reads contents from the temporary path', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fw');
    file_put_contents($tmp, 'hello');

    try {
        $file = new UploadedFile('note.txt', 'text/plain', 5, $tmp);

        expect($file->filename)->toBe('note.txt')
            ->and($file->mimeType)->toBe('text/plain')
            ->and($file->size)->toBe(5)
            ->and($file->contents())->toBe('hello');
    } finally {
        @unlink($tmp);
    }
});

it('stores the contents under a target directory and returns the path', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fw');
    file_put_contents($tmp, 'payload');
    $dir = sys_get_temp_dir().'/fw-store-'.bin2hex(random_bytes(4));

    try {
        $file = new UploadedFile('doc.bin', 'application/octet-stream', 7, $tmp);
        $stored = $file->store($dir, 'saved.bin');

        expect($stored)->toBe($dir.'/saved.bin')
            ->and(file_get_contents($stored))->toBe('payload');
    } finally {
        @unlink($tmp);
        @unlink($dir.'/saved.bin');
        @rmdir($dir);
    }
});

it('builds from an Illuminate uploaded file', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fw');
    file_put_contents($tmp, 'abc');

    try {
        $illuminate = new IlluminateUploadedFile($tmp, 'orig.txt', 'text/plain', null, true);
        $file = UploadedFile::fromIlluminate($illuminate);

        expect($file->filename)->toBe('orig.txt')
            ->and($file->mimeType)->toBe('text/plain')
            ->and($file->size)->toBe(3)
            ->and($file->contents())->toBe('abc');
    } finally {
        @unlink($tmp);
    }
});

it('rejects unsafe filenames when storing, to prevent path traversal', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fw');
    file_put_contents($tmp, 'payload');
    $dir = sys_get_temp_dir().'/firefly-web-'.uniqid();
    $escapedTarget = dirname($dir).'/evil.txt';

    try {
        $file = new UploadedFile('doc.bin', 'application/octet-stream', 7, $tmp);

        expect(fn () => $file->store($dir, '../evil.txt'))->toThrow(ConfigurationException::class)
            ->and(fn () => $file->store($dir, "a\0b"))->toThrow(ConfigurationException::class)
            ->and(file_exists($escapedTarget))->toBeFalse()
            ->and(is_dir($dir))->toBeFalse();
    } finally {
        @unlink($tmp);
        if (file_exists($escapedTarget)) {
            unlink($escapedTarget);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
});

it('still stores under a safe explicit name after the unsafe-name check', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fw');
    file_put_contents($tmp, 'payload');
    $dir = sys_get_temp_dir().'/firefly-web-'.uniqid();

    try {
        $file = new UploadedFile('doc.bin', 'application/octet-stream', 7, $tmp);
        $stored = $file->store($dir, 'ok.txt');

        expect($stored)->toBe($dir.'/ok.txt')
            ->and(file_get_contents($stored))->toBe('payload');
    } finally {
        @unlink($tmp);
        @unlink($dir.'/ok.txt');
        @rmdir($dir);
    }
});

it('throws an infrastructure exception when the temporary file cannot be read', function () {
    $missing = sys_get_temp_dir().'/firefly-web-'.uniqid().'-missing.tmp';
    $file = new UploadedFile('missing.txt', 'text/plain', 0, $missing);

    expect(fn () => $file->contents())->toThrow(InfrastructureException::class);
});
