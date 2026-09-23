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
 * The staging ends here.
 *
 * Wave R turned the guard on one surface at a time so that every commit in between could be green — the
 * README first, then the cross-cutting pages, then the thirty-two module guides, then each manuscript. That
 * is a good way to land a guard and a terrible thing to leave behind, because a staged list and a permanent
 * exemption are the same three lines of PHP: a page nobody got to and a page somebody took out read
 * identically once the wave is over, and the second one is how the whole guard quietly stops holding the
 * document that most needed it.
 *
 * So this asserts the surface is everything the repository publishes — and it does NOT ask a list in this
 * file which trees those are. THE REPOSITORY IS ASKED: `git ls-files '*.md'` is the definition of "ships",
 * because a file the index carries is a file that lands in the tarball Packagist serves, in the directory
 * `create-project` copies, or on the site. A Markdown file added later is audited the day it lands — it
 * fails here until it is — a directory dropped out of `AUDITED` fails here too, naming every page it took
 * with it, and so does a whole new top-level tree of documentation that nobody thought to name.
 *
 * That last case is why the enumeration moved out of this file. It used to walk a literal
 * `['docs', 'book/src', 'book/src-es']` plus `'README.md'`, which is a fine description of what wave R had
 * converted and a poor description of what ships: thirty-three tracked Markdown files sat outside it —
 * every `packages/*\/README.md`, which is the front page Packagist prints for that package, `skeleton/`'s and
 * `samples/lumen/`'s, `book/README.md`, and this repository's own CHANGELOG. Four of their listings were
 * untrue by the guard's own rules while a case named *audits every Markdown file that ships* stayed green
 * over them, which is the failure this test exists to make impossible.
 *
 * `docs/superpowers/` is the one exclusion, and it is not an exemption — it is git-ignored by policy, so
 * `git ls-files` never names it in the first place and the filter below is belt and braces. The floor on the
 * count is there so that a `git` that is missing, or a checkout that is not a repository, fails loudly
 * instead of asserting nothing at all.
 */
it('audits every Markdown file that ships', function () {
    $root = dirname(__DIR__);
    $audited = array_flip((new DocsCodeAudit($root))->markdownFiles());

    $tracked = [];
    $status = 0;
    // `core.quotePath=false` so a path with a non-ASCII character comes back as itself rather than as an
    // escaped, double-quoted string that would match nothing in the audited set and read as a missing file.
    exec(
        sprintf('git -C %s -c core.quotePath=false ls-files -- %s', escapeshellarg($root), escapeshellarg('*.md')),
        $tracked,
        $status,
    );

    expect($status)->toBe(0, 'git ls-files must run here: what ships is what the repository tracks, and this '
        .'test is worthless if it cannot ask');

    $shipped = array_values(array_filter(
        $tracked,
        static fn (string $file): bool => $file !== '' && ! str_starts_with($file, 'docs/superpowers/'),
    ));

    expect(count($shipped))->toBeGreaterThan(100, 'git ls-files returned implausibly few Markdown files, so '
        .'this case is not auditing what it claims to');

    $missing = array_values(array_filter(
        array_unique($shipped),
        static fn (string $file): bool => ! isset($audited[$file]),
    ));
    sort($missing);

    expect($missing)->toBe([], 'a Markdown file ships without being audited; audit it, do not exempt it: '
        .implode(', ', $missing));
});

/**
 * A `source:` marker is a HANDOVER, not a decoration, and this asserts that somebody is on the other end.
 *
 * The two halves of the book's gate own different listings. `book/build/verify_code.py` hands every ```php
 * block to `php -l` EXCEPT one carrying a `source:` marker, because a verbatim fragment of a real file — one
 * method out of its class, an interface's signatures — does not parse on its own. What it stops linting, this
 * file is supposed to pick up and compare against the file the marker names. That handover only happens for a
 * page inside `DocsCodeAudit::AUDITED`.
 *
 * So a manuscript page that gains markers WITHOUT joining the audited surface is checked by neither half: the
 * lint steps over it and the comparison never sees it. It ships green while nothing at all has read it. That
 * is strictly worse than the unconverted state, where at least `php -l` ran. It happened once, to
 * `book/src-es/10a-oauth2.md` — the first Spanish chapter ever written with markers, added while `AUDITED`
 * still named only `book/src` — and sixteen listings spent a commit unverified by anything.
 *
 * DERIVED FROM THE MARKERS THEMSELVES, in both trees, so the next chapter converted cannot re-open the hole
 * and no list here has to be kept in step with the manuscript. The fix a failure asks for is always the same
 * one line: name the file (or its directory) in `AUDITED`.
 */
it('audits every manuscript page that carries a source: marker', function () {
    $root = dirname(__DIR__);

    $audited = array_flip((new DocsCodeAudit($root))->markdownFiles());

    $marked = [];
    $orphaned = [];

    foreach (['book/src', 'book/src-es'] as $tree) {
        /** @var iterable<SplFileInfo> $walk */
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$tree, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            if (! str_contains((string) file_get_contents($file->getPathname()), '<!-- source:')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $marked[] = $relative;

            if (! isset($audited[$relative])) {
                $orphaned[] = $relative;
            }
        }
    }

    sort($orphaned);

    expect($marked)->not->toBe([], 'no manuscript page carries a `source:` marker any more, so this guard holds nothing')
        ->and($orphaned)->toBe([], 'a manuscript page carries `source:` markers, which take its listings out of '
            .'`php -l`, while DocsCodeAudit::AUDITED does not reach it — so nothing checks them at all. Add it '
            .'to AUDITED: '.implode(', ', $orphaned));
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

    // The three shapes a NESTED configuration block goes wrong in, and not one of them writes a dotted key
    // for the older scan to find: a misspelt leaf, a block repeated one level down (the copy-paste that
    // produces `openapi.viewer.viewer.style`), and a misspelt block that holds an application's own ids —
    // where the ids themselves are unknowable but the block above them is not.
    $misspeltLeaf = <<<'PHP'
    return [
        'openapi' => [
            'enabled' => true,
            'viewer' => ['styl' => 'swagger'],
        ],
    ];
    PHP;

    $nestedTwice = <<<'PHP'
    return [
        'openapi' => [
            'viewer' => ['viewer' => ['style' => 'swagger']],
        ],
    ];
    PHP;

    $misspeltBlock = <<<'PHP'
    return [
        'security' => [
            'oauth2' => [
                'client' => [
                    'registrations' => [
                        'google' => ['client_id' => 'id', 'client_secret' => 'secret'],
                    ],
                ],
            ],
        ],
    ];
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

        // (c) ILLUSTRATIVE, CONFIGURATION — the shape a reader pastes into config/firefly.php. These are the
        // listings the dotted scan below is blind to: `'viewer' => ['styl' => …]` names no `firefly.*` token
        // at all, and three package front pages open on exactly this shape.
        ['a nested configuration listing whose leaf nothing reads', $repository,
            docsCodeBlock('php', $misspeltLeaf, null, 'the openapi keys an application writes into its own config/firefly.php'),
            'writes the configuration key `firefly.openapi.viewer.styl`'],
        ['a configuration block repeated one level too deep', $repository,
            docsCodeBlock('php', $nestedTwice, null, 'the openapi keys an application writes into its own config/firefly.php'),
            'writes the configuration key `firefly.openapi.viewer.viewer`'],
        ['a misspelt block holding an application\'s own ids', $repository,
            docsCodeBlock('php', $misspeltBlock, null, 'the registrations an application declares in its own config/firefly.php'),
            'writes the configuration key `firefly.security.oauth2.client.registrations`'],

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

    // Everything a real configuration listing is allowed to contain, in one block: settings that resolve,
    // a LIST of rows whose own keys are not settings at all (`rules.0.pattern` is nobody's key), and an
    // application-keyed block whose ids and whose entries the framework cannot possibly contain.
    $configuration = <<<'PHP'
    return [
        'security' => [
            'enabled' => true,
            'http' => ['enabled' => true, 'rules' => [['pattern' => '*', 'access' => 'authenticated']]],
            'oauth2' => [
                'client' => [
                    'enabled' => true,
                    'registration' => [
                        'google' => ['client_id' => 'id', 'client_secret' => 'secret'],
                    ],
                ],
            ],
        ],
    ];
    PHP;

    // The negative half of the same rule: an array whose top-level keys name no section is not configuration
    // and is not judged as any. Read as settings it would refuse `firefly.name` and `firefly.message`, and
    // the documents are full of JSON bodies, filters and fixtures written exactly like this.
    $notConfiguration = <<<'PHP'
    return ['name' => 'Ada', 'message' => 'Hello, Ada'];
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
        ['a nested configuration listing whose every key resolves', $repository,
            docsCodeBlock('php', $configuration, null, 'the registrations an application declares in its own config/firefly.php')],
        ['an array that is not configuration at all', $repository,
            docsCodeBlock('php', $notConfiguration, null, 'the JSON body a controller the reader writes returns')],
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
