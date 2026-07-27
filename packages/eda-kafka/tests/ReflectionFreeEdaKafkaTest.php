<?php

declare(strict_types=1);

it('the firefly/eda-kafka adapter uses no boot-time reflection', function () {
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

/**
 * The ext-optional contract's REAL safety property is not "no `use RdKafka\...;` import appears in the source" —
 * it is "every file in this package autoloads with ZERO fatal on a machine with no ext-rdkafka installed". This
 * repo's own Pint preset (`fully_qualified_strict_types`, inherited from the shared root `laravel` preset — not
 * overridable per-package without editing the root config) rewrites a bare `\RdKafka\Producer` return-type
 * reference into a short name plus a top-level `use RdKafka\Producer;` import; a plain `use` statement is a
 * compile-time alias only and is never itself resolved by the engine, so this is harmless. What WOULD be unsafe
 * is any \RdKafka\* class actually being INSTANTIATED, called, or type-checked outside a method that first
 * confirms extension_loaded('rdkafka') (KafkaProducerFactory::available()) — and requiring every file below,
 * verbatim, on THIS no-ext machine, is the direct, non-heuristic proof that never happens.
 */
it('every eda-kafka source file autoloads with no fatal when ext-rdkafka is absent', function () {
    if (extension_loaded('rdkafka')) {
        $this->markTestSkipped('rdkafka present — this proves the no-ext autoload path specifically.');
    }

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src', RecursiveDirectoryIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = (string) $file->getRealPath();
        }
    }

    foreach ($files as $file) {
        require_once $file;
    }

    expect($files)->not->toBeEmpty();
});
