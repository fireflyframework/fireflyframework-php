<?php

declare(strict_types=1);

it('the firefly/eda-postgres adapter uses no boot-time reflection', function () {
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', RecursiveDirectoryIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && preg_match('/ReflectionClass|ReflectionMethod|getAttributes/', (string) file_get_contents((string) $file->getRealPath())) === 1) {
            $hits[] = $file->getBasename();
        }
    }
    sort($hits);
    expect($hits)->toBe([]);
});
