<?php

declare(strict_types=1);

/** @return list<string> basenames of src files that reflect attributes, classes or methods */
function featureFlagsReflectionHits(string $dir): array
{
    $hits = [];
    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents((string) $file->getRealPath());
        // Reflection* CLASSES (the security guard's rule), plus an attribute read — getAttributes( with an
        // argument. OpenFeature's EvaluationContext::getAttributes() takes none and is not reflection.
        if (preg_match('/Reflection(Class|Method|Function|Parameter|Attribute)\b|->getAttributes\(\s*[A-Z\\\\]/', $contents) === 1) {
            $hits[] = $file->getBasename();
        }
    }
    sort($hits);

    return $hits;
}

it('confines firefly/feature-flags reflection to the single sanctioned FeatureFlagScanner', function (): void {
    expect(featureFlagsReflectionHits(dirname(__DIR__).'/src'))->toBe(['FeatureFlagScanner.php']);
});
