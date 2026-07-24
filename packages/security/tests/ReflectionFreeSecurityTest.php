<?php

declare(strict_types=1);

/** @return list<string> basenames of .php files that name boot/scan-time reflection */
function securityReflectionHits(string $dir): array
{
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents((string) $file->getRealPath());
        // Match Reflection* CLASSES only — NOT the bare domain method getAttributes(), which Authentication
        // legitimately declares (Spring's attribute accessor). Attribute reflection is impossible without a
        // Reflection* object, so coverage is preserved while the false-positive is eliminated.
        if (preg_match('/Reflection(Class|Method|Function|Parameter|Attribute)\b/', $contents) === 1) {
            $hits[] = $file->getBasename();
        }
    }
    sort($hits);

    return $hits;
}

it('confines firefly/security reflection to the single sanctioned MethodSecurityScanner', function () {
    expect(securityReflectionHits(__DIR__.'/../src'))->toBe(['MethodSecurityScanner.php']);
});
