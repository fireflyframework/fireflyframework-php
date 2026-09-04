<?php

declare(strict_types=1);

/** @return list<string> basenames of .php files under $dir that reference boot-time reflection */
function reflectionHits(string $dir): array
{
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents((string) $file->getRealPath());
        if (preg_match('/ReflectionClass|ReflectionMethod|getAttributes/', $contents) === 1) {
            $hits[] = $file->getBasename();
        }
    }
    sort($hits);

    return $hits;
}

/**
 * The invariant is about the CACHED boot: once firefly:cache has emitted the manifests, resolving a bean and
 * dispatching a request must touch no reflection at all. Compile-time code is therefore allowlisted by name,
 * not exempted wholesale — each entry below has to earn its place.
 *
 *   ConstraintScanner            reads #[Constraint] attributes off a DTO. The original scan.
 *   ConstraintManifestCompiler   recovers a custom ValidationRule's constructor arguments from its promoted
 *                                properties so #[Rules] survives var_export. Runs only inside firefly:cache;
 *                                a rule that cannot be recovered this way implements Compilable instead, and
 *                                one that does neither is rejected at COMPILE time with an actionable message
 *                                rather than silently losing its state at runtime.
 *
 * An uncached (development) boot does run these — that is what "scanned boot" means, and it is the documented
 * trade-off, not a violation of this invariant.
 */
it('the boot path of firefly/autoconfigure and firefly/validation contains no reflection', function () {
    expect(reflectionHits(__DIR__.'/../src'))->toBe([])
        ->and(reflectionHits(dirname(__DIR__, 2).'/validation/src'))
        ->toBe(['ConstraintManifestCompiler.php', 'ConstraintScanner.php']);
});

it('firefly/context reflection is still confined to its one scanner (standing M4 invariant)', function () {
    expect(reflectionHits(dirname(__DIR__, 2).'/context/src'))->toBe(['ContextScanner.php']);
});
