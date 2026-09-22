<?php

// tests/DocsCodeIsRealTest.php

declare(strict_types=1);

use Firefly\Tests\Support\DocsCodeAudit;

/**
 * Every failure is collected before anything is asserted, so one run names every untrue listing in the
 * audited surface rather than the first. The message a failure carries is the fix, not a diagnosis.
 */
it('ships documentation whose every code listing is real', function () {
    $audit = new DocsCodeAudit(dirname(__DIR__));

    $failures = [];

    foreach ($audit->markdownFiles() as $file) {
        foreach ($audit->blocksIn($file) as $block) {
            $failure = $audit->verify($block);

            if ($failure !== null) {
                $failures[] = $block->where().' ['.($block->language === '' ? 'text' : $block->language).'] '.$failure;
            }
        }
    }

    expect($failures)->toBe([]);
});

/**
 * The audited surface is staged — wave R turned the guard on one area at a time — and the staging must not
 * outlive the wave. This asserts the two things that keep it honest: every entry resolves, and README.md,
 * the first file anyone reads, is never removed from it.
 */
it('audits a surface that only ever grows', function () {
    $root = dirname(__DIR__);

    foreach (DocsCodeAudit::AUDITED as $entry) {
        expect(file_exists($root.'/'.$entry))->toBeTrue("DocsCodeAudit::AUDITED names a missing path: {$entry}");
    }

    expect(DocsCodeAudit::AUDITED)->toContain('README.md');
});
