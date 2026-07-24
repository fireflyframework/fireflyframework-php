<?php

declare(strict_types=1);

it('contains no dynamic-execution primitives anywhere in firefly/security/src', function () {
    $forbidden = '/\b(eval|create_function|call_user_func|call_user_func_array|assert|proc_open|shell_exec|passthru|system|exec)\s*\(/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', RecursiveDirectoryIterator::SKIP_DOTS));
    $offenders = [];
    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match($forbidden, (string) file_get_contents((string) $file->getRealPath())) === 1) {
            $offenders[] = $file->getBasename();
        }
    }

    expect($offenders)->toBe([]);
});
