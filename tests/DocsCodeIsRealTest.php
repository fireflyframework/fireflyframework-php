<?php

// tests/DocsCodeIsRealTest.php

declare(strict_types=1);

use Firefly\Tests\Support\DocsCodeAudit;
use Firefly\Tests\Support\DocsCodeBlock;

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

    expect(DocsCodeAudit::AUDITED)->toContain('README.md')
        // The manuscript joined the audited surface in wave R, and every English listing was given a marker
        // to make that possible. Removing the entry would leave 274 `source:` comments in book/src that
        // nothing reads — the exact state README.md was in before this guard existed — while
        // docs/contributing.md went on telling contributors the book is under the provenance contract.
        ->and(DocsCodeAudit::AUDITED)->toContain('book/src');
});

/**
 * A throwaway repository for the PROVENANCE cases.
 *
 * The verbatim comparison is the one contract whose fixtures must not be the framework's own sources: a test
 * that excerpts a real package file passes or fails on whether somebody refactored that file this morning,
 * which is a test about the wrong thing. This writes the two files the cases point at — one PHP class and one
 * YAML document — plus the two manifests composerScripts() reads, so an audit rooted here is complete.
 *
 * @return array{0: string, 1: list<string>, 2: list<string>} the root, its files and its directories
 */
function docsCodeAuditFixture(): array
{
    $root = sys_get_temp_dir().'/firefly-docs-audit-'.bin2hex(random_bytes(6));

    $directories = [$root, $root.'/src', $root.'/config', $root.'/routes', $root.'/skeleton'];

    foreach ($directories as $directory) {
        mkdir($directory, 0o777, true);
    }

    $greeter = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App;

    /**
     * Greets a person by name.
     *
     * A second paragraph, so an excerpt can start below the opener and still be verbatim — the shape
     * verifyExcerpt()'s third rule refuses.
     */
    final class Greeter
    {
        private const GREETING = 'Hello';

        public function greet(string $name): string
        {
            return self::GREETING.', '.$name;
        }
    }

    PHP;

    $files = [
        $root.'/src/Greeter.php' => $greeter,
        $root.'/config/app.yml' => "service:\n  name: greeter\n  enabled: true\n",
        $root.'/routes/web.php' => "<?php\n\n// Nothing here yet.\n",
        $root.'/composer.json' => '{"scripts": {"test": "pest"}}',
        $root.'/skeleton/composer.json' => '{"scripts": {}}',
        $root.'/NOTES.md' => "# Notes\n",
    ];

    foreach ($files as $file => $contents) {
        file_put_contents($file, $contents);
    }

    return [$root, array_keys($files), array_reverse($directories)];
}

/**
 * @param  list<string>  $files
 * @param  list<string>  $directories
 */
function docsCodeAuditCleanUp(array $files, array $directories): void
{
    foreach ($files as $file) {
        @unlink($file);
    }

    foreach ($directories as $directory) {
        @rmdir($directory);
    }
}

function docsCodeBlock(string $language, string $code, ?string $source = null, ?string $illustrative = null): DocsCodeBlock
{
    return new DocsCodeBlock('fixture.md', 1, $language, $code, $source, $illustrative);
}

/**
 * The guard's own teeth, one contract at a time.
 *
 * The two tests above are about the DOCUMENTS: they pass when README.md is true. Neither is about the guard,
 * and that gap has a sharp edge — replace DocsCodeAudit::verify() with `return null;` and both stay green
 * while every listing in the repository silently stops being checked. The README now points readers at this
 * file as the reason to trust what they read, so a guard that can quietly stop guarding is precisely the
 * failure mode it was written to remove.
 *
 * Each case therefore breaks exactly one promise, and the test asserts two things: that the guard refuses the
 * listing at all, and that the message names the promise that was broken — a message that describes the wrong
 * fault is a message a contributor cannot act on. Four of these are regressions rather than hypotheticals,
 * each verified against this repository the day it was written: a `source:` marker on a non-`php` fence was
 * ignored entirely (the named file was not even required to exist), a grouped `use A\{B, Invented};` matched
 * no import pattern and so was never resolved, the same grouped member then whitelisted its own
 * `#[Invented]` attribute, and a Laravel Context key (`firefly.trace_id`) was accepted as a configuration
 * setting because the key scan was a bare grep for `firefly.` over every source file.
 */
it('refuses a listing that breaks any one of its contracts, and names the promise it broke', function () {
    [$root, $files, $directories] = docsCodeAuditFixture();

    $repository = new DocsCodeAudit(dirname(__DIR__));
    $fixture = new DocsCodeAudit($root);

    $illustrative = 'a controller the reader writes in their own application';

    $invented = <<<'PHP'
    use Firefly\Container\Attributes\NotAThing;

    final class Thing {}
    PHP;

    $inventedInGroup = <<<'PHP'
    use Firefly\Container\Attributes\{Service, TotallyInvented};

    #[Service]
    #[TotallyInvented]
    final class Thing {}
    PHP;

    $fabricated = <<<'PHP'
    #[Fabricated]
    final class Thing {}
    PHP;

    /** @var list<array{0: string, 1: DocsCodeAudit, 2: DocsCodeBlock, 3: string}> $cases */
    $cases = [
        // (a) PROVENANCE — the marker is a claim about a file, on any fence.
        ['a php listing with no marker at all', $repository,
            docsCodeBlock('php', '$total = 1 + 1;'), 'no provenance'],
        ['a source that does not exist', $fixture,
            docsCodeBlock('php', '$x = 1;', 'src/Missing.php'), 'names a source that does not exist'],
        ['a Markdown file offered as the source', $fixture,
            docsCodeBlock('php', '$x = 1;', 'NOTES.md'), 'names a Markdown file as its source'],
        ['two source files at once', $fixture,
            docsCodeBlock('php', '$x = 1;', 'src/Greeter.php,config/app.yml'), 'names more than one source file'],
        ['a listing that is not verbatim', $fixture,
            docsCodeBlock('php', "private const GREETING = 'Howdy';", 'src/Greeter.php'), 'is not verbatim'],
        ['a source marker on a yaml fence', $fixture,
            docsCodeBlock('yaml', "service:\n  name: greeter", 'config/missing.yml'), 'names a source that does not exist'],
        ['a source marker on a json fence', $fixture,
            docsCodeBlock('json', '{"scripts": {"test": "pest"}}', 'composer.lock'), 'names a source that does not exist'],
        ['a yaml excerpt that is not verbatim', $fixture,
            docsCodeBlock('yaml', "service:\n  name: wrong", 'config/app.yml'), 'is not verbatim'],
        // Both of these ARE verbatim — every line is in the file, in order — and both are useless to a
        // reader, which is the whole reason verbatim-ness alone is not the contract. The first lost the
        // `final class Greeter` line to its cut and prints a brace with nothing above it; the second prints
        // a method that appears to do nothing. `php -l` used to refuse the first shape and no longer sees
        // these listings at all, so the structural promise is kept here instead.
        ['an excerpt whose cut swallowed the declaration', $fixture,
            docsCodeBlock('php', "namespace App;\n// …\n{", 'src/Greeter.php'), 'cuts away the declaration'],
        ['an excerpt whose body was cut down to nothing', $fixture,
            docsCodeBlock('php', "public function greet(string \$name): string\n{\n// …\n}", 'src/Greeter.php'),
            'hollows a body out to nothing'],
        // The third shape, and the one that shipped six times in a single wave: an excerpt that begins
        // partway down a docblock. Every line is verbatim, nothing parses, and the page prints a paragraph
        // about a declaration it never shows — the worst of the six described actingAsOidcUser() for ten
        // lines without naming it. The three cases are the three ways the subject can go missing.
        ['a docblock excerpted below its opener', $fixture,
            docsCodeBlock('php', ' * A second paragraph, so an excerpt can start below the opener and still be verbatim — the shape', 'src/Greeter.php'),
            'without its `/**` opener'],
        ['a docblock whose closer was cut away with the declaration', $fixture,
            docsCodeBlock('php', "/**\n * Greets a person by name.", 'src/Greeter.php'),
            'opens a docblock it never closes'],
        ['a docblock that stops at its own closer', $fixture,
            docsCodeBlock('php', "/**\n * Greets a person by name.\n// …\n */", 'src/Greeter.php'),
            'closes its docblock and then stops'],

        // (c) ILLUSTRATIVE — a stated reason, code that parses, and symbols that exist.
        ['an illustrative reason nobody can act on', $repository,
            docsCodeBlock('php', '$x = 1;', null, 'demo'), 'no reason a reader could act on'],
        ['an illustrative listing that does not parse', $repository,
            docsCodeBlock('php', 'final class Broken {', null, $illustrative), 'does not parse'],
        ['an import of a class the framework does not have', $repository,
            docsCodeBlock('php', $invented, null, $illustrative), 'imports a framework class that does not exist'],
        ['a grouped import that invents one of its members', $repository,
            docsCodeBlock('php', $inventedInGroup, null, $illustrative), 'Firefly\Container\Attributes\TotallyInvented'],
        ['an attribute nothing declares or imports', $repository,
            docsCodeBlock('php', $fabricated, null, $illustrative), 'uses #[Fabricated]'],

        // (b) SHAPE — the keys, commands and scripts a listing names must exist.
        ['a configuration key nothing reads', $repository,
            docsCodeBlock('bash', 'FIREFLY=1 # firefly.totally.invented.key'), 'names the configuration key `firefly.totally.invented.key`'],
        ['a Context key offered as a setting', $repository,
            docsCodeBlock('bash', 'php artisan tinker # firefly.trace_id'), 'names the configuration key `firefly.trace_id`'],
        // The provenance exemption is not a way in through the side door. `skeleton/config/firefly.php` is the
        // documented reference, its prose mentions `firefly.trace_id`, and an excerpt of THAT file is exactly
        // the listing a reader would copy into their own config — so the source has to be framework code under
        // packages/*/src before literalProvenInSource() proves anything.
        ['a reference-file excerpt that names a Context key', $repository,
            docsCodeBlock(
                'text',
                '| PRODUCER/CONSUMER spans. The trace and span ids reach Laravel Context (firefly.trace_id /',
                'skeleton/config/firefly.php',
            ),
            'names the configuration key `firefly.trace_id`'],
        ['an artisan command no $signature declares', $repository,
            docsCodeBlock('bash', 'php artisan firefly:not-a-command'), 'which no $signature under packages/*/src declares'],
        ['a composer script nothing defines', $repository,
            docsCodeBlock('bash', 'composer not-a-script'), 'neither a Composer command nor a script'],
    ];

    $accepted = [];
    $misdescribed = [];

    foreach ($cases as [$name, $audit, $block, $expected]) {
        $failure = $audit->verify($block);

        if ($failure === null) {
            $accepted[] = $name;

            continue;
        }

        if (! str_contains($failure, $expected)) {
            $misdescribed[] = $name.' — expected a message containing '.var_export($expected, true).', got: '.$failure;
        }
    }

    docsCodeAuditCleanUp($files, $directories);

    expect($accepted)->toBe([])
        ->and($misdescribed)->toBe([]);
});

/**
 * The other half of the same guard: everything a document is ALLOWED to write must stay writeable.
 *
 * A check that buys its strictness by rejecting legal listings is worse than no check, because the way out is
 * to mutilate the excerpt until it passes — and a mutilated excerpt is exactly the untrue listing this file
 * exists to catch. These are the shapes the README actually uses: an excerpt cut with the elision marker, a
 * method lifted out of its class to column 0, a non-PHP excerpt of a real file, a grouped import of classes
 * that do exist, and a shell listing naming a real command, a real script and a real key.
 */
it('accepts every listing its contracts allow', function () {
    [$root, $files, $directories] = docsCodeAuditFixture();

    $repository = new DocsCodeAudit(dirname(__DIR__));
    $fixture = new DocsCodeAudit($root);

    $elided = <<<'PHP'
    final class Greeter
    {
        // …

        public function greet(string $name): string
        {
            return self::GREETING.', '.$name;
        }
    }
    PHP;

    $dedented = <<<'PHP'
    public function greet(string $name): string
    {
        return self::GREETING.', '.$name;
    }
    PHP;

    // The legal half of the docblock rule: opener, a cut, closer, and the declaration the docblock is about.
    $documented = <<<'PHP'
    /**
     * Greets a person by name.
    // …
     */
    final class Greeter
    PHP;

    $grouped = <<<'PHP'
    use Firefly\Container\Attributes\{Primary, Qualifier, Service};

    #[Service]
    #[Primary]
    final class PrimaryGreeter {}
    PHP;

    /** @var list<array{0: string, 1: DocsCodeAudit, 2: DocsCodeBlock}> $cases */
    $cases = [
        ['an excerpt cut with the elision marker', $fixture, docsCodeBlock('php', $elided, 'src/Greeter.php')],
        ['a method lifted out of its class', $fixture, docsCodeBlock('php', $dedented, 'src/Greeter.php')],
        ['a docblock shown with the declaration it documents', $fixture, docsCodeBlock('php', $documented, 'src/Greeter.php')],
        // A listing with no docblock line in it is outside the third rule entirely: a file whose whole
        // content is a `//` comment (skeleton/routes/web.php) and a commented-out configuration block
        // (the error-page section of skeleton/config/firefly.php) are both legitimate things to quote.
        ['a listing that is nothing but line comments', $fixture,
            docsCodeBlock('php', '// Nothing here yet.', 'routes/web.php')],
        ['a yaml excerpt of a real file', $fixture, docsCodeBlock('yaml', "service:\n  name: greeter", 'config/app.yml')],
        ['a yaml excerpt cut with the hash elision', $fixture, docsCodeBlock('yaml', "service:\n# …\n  enabled: true", 'config/app.yml')],
        ['a grouped import of classes that exist', $repository, docsCodeBlock('php', $grouped, null, 'the primary bean an application declares for itself')],
        ['a shell listing of real commands and keys', $repository, docsCodeBlock('bash', "php artisan firefly:about\ncomposer stan\n# firefly.security.enabled=true")],
        // The two shapes literalProvenInSource() exists for, and the reason it exists: the README's
        // TracingFilter showcase is a verbatim excerpt of a call whose span-attribute array carries a Laravel
        // Context key, and the key check used to read that line as configuration and refuse it — which left
        // the document printing an elision where the framework ships an argument. Both listings are one line
        // each on purpose: the contract being pinned is the exemption, not either file's formatting.
        ['a source excerpt declaring a Context key as a constant', $repository,
            docsCodeBlock('php', "public const CONTEXT_KEY = 'firefly.correlation_id';", 'packages/web/src/Filter/CorrelationIdFilter.php')],
        ['a source excerpt whose span attributes include a Context key', $repository,
            docsCodeBlock(
                'php',
                "'firefly.correlation_id' => (string) \$request->headers->get(CorrelationIdFilter::HEADER, ''),",
                'packages/observability/src/Web/TracingFilter.php',
            )],
    ];

    $refused = [];

    foreach ($cases as [$name, $audit, $block]) {
        $failure = $audit->verify($block);

        if ($failure !== null) {
            $refused[] = $name.' — '.$failure;
        }
    }

    docsCodeAuditCleanUp($files, $directories);

    expect($refused)->toBe([]);
});
