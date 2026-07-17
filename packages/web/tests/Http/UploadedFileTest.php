<?php

declare(strict_types=1);

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
            ->and($file->contents())->toBe('abc');
    } finally {
        @unlink($tmp);
    }
});
