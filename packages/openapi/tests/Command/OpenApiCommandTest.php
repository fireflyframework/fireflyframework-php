<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\OpenApiCapstoneTestCase;
use Illuminate\Support\Facades\Artisan;

uses(OpenApiCapstoneTestCase::class);

it('writes the document to stdout as raw, pipeable JSON', function () {
    /** @var OpenApiCapstoneTestCase $this */
    // Artisan::call(), NOT $this->artisan(): the PendingCommand helper substitutes a MOCK OutputStyle and
    // asserts against expectations set on it, so the bytes this command writes never reach a real buffer
    // there. Capturing stdout is the entire point of this test, so it goes through the kernel for real.
    expect(Artisan::call('firefly:openapi'))->toBe(0);

    // `php artisan firefly:openapi > openapi.json` has to produce a byte-exact document, so stdout must
    // carry the JSON and nothing else — no banner, no summary line, and no Symfony formatter rewriting of
    // any `<...>` sequence that reaches the document from a docblock or a config value.
    /** @var array<string, mixed> $document */
    $document = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['paths'])->toHaveKey('/api/orders');
});

it('writes the document to a file and creates the parent directory', function () {
    /** @var OpenApiCapstoneTestCase $this */
    $target = sys_get_temp_dir().'/firefly-openapi-'.bin2hex(random_bytes(6)).'/api/openapi.json';

    try {
        expect(Artisan::call('firefly:openapi', ['--output' => $target]))->toBe(0)
            ->and(is_file($target))->toBeTrue();

        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($target), true, flags: JSON_THROW_ON_ERROR);

        expect($document['openapi'])->toBe('3.1.0')
            ->and($document['components'])->toHaveKey('schemas');
    } finally {
        @unlink($target);
        @rmdir(dirname($target));
        @rmdir(dirname($target, 2));
    }
});

it('produces the same bytes on the file path as on stdout, plus a trailing newline', function () {
    /** @var OpenApiCapstoneTestCase $this */
    // A committed spec file that differs from the served one — even by whitespace — turns every CI diff into
    // noise, so the two serialisations must be the same document.
    $target = sys_get_temp_dir().'/firefly-openapi-'.bin2hex(random_bytes(6)).'.json';

    try {
        expect(Artisan::call('firefly:openapi', ['--output' => $target]))->toBe(0)
            ->and(Artisan::call('firefly:openapi'))->toBe(0)
            ->and((string) file_get_contents($target))->toBe(trim(Artisan::output()).PHP_EOL);
    } finally {
        @unlink($target);
    }
});
