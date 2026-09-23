<?php

// tests/DocsProseIsRealTest.php

declare(strict_types=1);

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Config\Config;
use Firefly\Data\Exception\DriverErrorTable;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Locking\HasOptimisticLock;
use Firefly\Data\Repository\Locking\OptimisticLockException;
use Firefly\Installer\CapabilityCatalog;
use Firefly\Kernel\Exception\Infrastructure\OptimisticLockingFailureException;
use Firefly\Security\Access\Expression\ExpressionParseException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * The guard for documentation PROSE, the half `tests/DocsCodeIsRealTest.php` cannot see.
 *
 * That test compares a fenced listing against the file it names, byte for byte, which is why a wrong listing
 * cannot ship. A wrong SENTENCE can: nothing compares "produces OptimisticLockingFailureException" or
 * "unexposed (404) until named" against anything, and all three claims below shipped in exactly that shape —
 * each one a statement a reader would act on (leave an endpoint unguarded because it is "404 anyway", catch a
 * type the translator never raises, write an expression the tokenizer rejects).
 *
 * The technique is the one the diagram guard already uses: the truth is DERIVED from the framework at test
 * time — the evaluator's own parser decides which function names exist, ExposureModel's own default decides
 * which endpoints answer on a fresh install, every endpointId() in the tree decides how many endpoints there
 * are to count, the translator's own match decides which exceptions it builds — and the documents are then
 * held against that. Nothing here types out an answer that the source could contradict, so the day the code
 * changes, the failure names the sentence that has to change with it.
 *
 * A COUNT IS A CLAIM, and the cheapest one to get wrong. "Eight function names, total" outlived two additions
 * to the whitelist, and "Fifteen ship today" is one new endpoint away from being false with nothing edited.
 * So wherever a page counts a set this file has already derived, the number is read and compared — see
 * fireflyWrittenNumber(), which reads the word as well as the digit because that is how these sentences are
 * written.
 *
 * Scope: every `docs/**.md`, every `book/src/**.md` and `book/src-es/**.md`, plus `README.md` — see
 * fireflyProsePages(), which says what the book cost while it was outside. The triggers are deliberately
 * narrow — a paragraph is only checked when it makes the kind of exhaustive claim that can be false — so a
 * page is free to mention `hasRole()` or `/actuator/env` in passing without owing the full enumeration.
 */

/**
 * Every Markdown page the framework publishes, split into blank-line-separated paragraphs.
 *
 * `docs/superpowers/**` is git-ignored working material, never shipped, and is skipped.
 *
 * THE BOOK IS PART OF THE SURFACE, and leaving it out cost something real. The expression whitelist grew
 * `hasScope`/`hasAnyScope` and the correction reached `README.md`, `docs/architecture.md`,
 * `docs/modules/security.md` and the evaluator's own docblock — but not `book/src/10-security.md`, which went
 * on telling a reader that eight function names are all the tokenizer can reach, beside a `dispatch()`
 * listing that had neither of the two new ones in it. A reader writing `hasScope('orders:read')` was being
 * told by the manuscript that it could not work. Nothing could see that: this file walked `docs/` only, and
 * the book is not in DocsCodeAudit::AUDITED either. The sibling guard in tests/DocsDiagramsTest.php had
 * already reached the same conclusion and walks these three trees; this one now walks them too, so a fact
 * corrected in the documentation cannot stay wrong in the manuscript.
 *
 * @return array<string, list<string>> repo-relative path => paragraphs
 */
function fireflyProsePages(): array
{
    $root = dirname(__DIR__);

    $paths = [$root.'/README.md'];
    foreach (['docs', 'book/src', 'book/src-es'] as $directory) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'md' && ! str_contains($file->getPathname(), '/docs/superpowers/')) {
                $paths[] = $file->getPathname();
            }
        }
    }
    sort($paths);

    $pages = [];
    foreach ($paths as $path) {
        $paragraphs = preg_split('/\n\s*\n/', (string) file_get_contents($path));
        $pages[substr($path, strlen($root) + 1)] = $paragraphs === false ? [] : $paragraphs;
    }

    return $pages;
}

/**
 * The number a sentence writes, as an integer — or null when the token is not a number at all.
 *
 * Documentation counts things in words, not digits: "Fifteen ship today", "Ten function names, total",
 * "Diez nombres de función". A guard that only understood `15` would be blind to every sentence a person
 * would actually write, which is how "Eight function names, total" survived two functions being added to the
 * whitelist and how an actuator inventory can go stale without a diff. Both languages the project publishes
 * in are here for the same reason the page walk covers `book/src-es`: a fact corrected in one must not stay
 * wrong in the other.
 *
 * One to twenty is the whole range, deliberately. These are counts of endpoints, filters and whitelist
 * entries — sets a person enumerates in a sentence — and a set large enough to need "twenty-three" is one no
 * document spells out by hand. A number outside the range reads as "not a count" and the caller skips it, so
 * the failure mode is a check that does not fire rather than one that fires wrongly; the test that cares
 * carries a canary on how many counts it found, which is what turns that silence red.
 */
function fireflyWrittenNumber(string $token): ?int
{
    /** @var array<string, int> $words */
    static $words = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
        'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
        'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
        'nineteen' => 19, 'twenty' => 20,
        'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6,
        'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12, 'trece' => 13,
        'catorce' => 14, 'quince' => 15, 'dieciséis' => 16, 'diecisiete' => 17, 'dieciocho' => 18,
        'diecinueve' => 19, 'veinte' => 20,
    ];

    if (preg_match('/^\d+$/', $token) === 1) {
        return (int) $token;
    }

    return $words[mb_strtolower($token)] ?? null;
}

/**
 * A Markdown table row, as its trimmed cells.
 *
 * @return list<string>
 */
function fireflyTableCells(string $row): array
{
    return array_map('trim', explode('|', trim($row, "| \t")));
}

/**
 * Every attribute class the framework ships, keyed by the SHORT name documentation writes inside `#[...]`.
 *
 * The map exists so a sentence about the stereotype hierarchy can be held against PHP's own answer. Only
 * `packages/*\/src/**\/Attributes/*.php` is walked: those directories are where every stereotype lives, the
 * scan stays cheap, and a name the map does not know makes the caller SKIP rather than fail — a document is
 * free to write `#[Attribute]` or some vendor's attribute in passing without owing this guard anything. A
 * short name that two packages both declare is dropped for the same reason, because resolving it would be a
 * guess; nothing in the tree does that today, and the caller's canary count is what would notice if the day
 * came that a silently-dropped name mattered.
 *
 * @return array<string, class-string>
 */
function fireflyAttributeClasses(): array
{
    /** @var array<string, class-string>|null $classes */
    static $classes = null;

    if ($classes !== null) {
        return $classes;
    }

    /** @var array<string, class-string> $found */
    $found = [];
    /** @var list<string> $ambiguous */
    $ambiguous = [];

    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__).'/packages', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($walk as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('#/packages/[^/]+/src/(?:.+/)?Attributes/[^/]+\.php$#', $file->getPathname()) !== 1) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, '#[Attribute(') || preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
            continue;
        }

        $short = $file->getBasename('.php');
        $candidate = trim($matches[1]).'\\'.$short;

        if (! class_exists($candidate)) {
            continue;
        }

        if (isset($found[$short]) && $found[$short] !== $candidate) {
            $ambiguous[] = $short;

            continue;
        }

        $found[$short] = $candidate;
    }

    foreach ($ambiguous as $short) {
        unset($found[$short]);
    }

    return $classes = $found;
}

/**
 * Every `make:firefly-*` generator, keyed by the command name a document writes, with the two facts a
 * documentation table can get wrong about it: the options it declares ITSELF, and whether it writes a second
 * file.
 *
 * Both are derived. The options are the ones whose `getOptions()` the Firefly command declares — the
 * parent's are Laravel's to document, not this repository's — read off an uninitialised instance, which is
 * safe because every one of those methods returns a literal and touches no state. The second file is read
 * off the command's own source: `GeneratorCommand` performs the FIRST write itself, so a `$this->files->put(`
 * in a subclass is by construction an additional file, and that is exactly the fact a table row saying "a
 * #[RestController] with a sample action" was hiding while the command shipped a REST resource AND its
 * request DTO.
 *
 * @return array<string, array{class: class-string, options: list<string>, secondFile: bool}>
 */
function fireflyGenerators(): array
{
    /** @var array<string, array{class: class-string, options: list<string>, secondFile: bool}>|null $generators */
    static $generators = null;

    if ($generators !== null) {
        return $generators;
    }

    $found = [];

    foreach ((array) glob(dirname(__DIR__).'/packages/cli/src/Command/Make/*Command.php') as $path) {
        $path = (string) $path;
        $source = (string) file_get_contents($path);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
            continue;
        }

        $class = trim($matches[1]).'\\'.basename($path, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $name = $reflection->getDefaultProperties()['name'] ?? null;

        if (! is_string($name) || ! str_starts_with($name, 'make:firefly-')) {
            continue;
        }

        $options = [];

        if ($reflection->hasMethod('getOptions') && $reflection->getMethod('getOptions')->getDeclaringClass()->getName() === $class) {
            /** @var list<array{0: string}> $declared */
            $declared = (array) $reflection->getMethod('getOptions')->invoke($reflection->newInstanceWithoutConstructor());

            foreach ($declared as $option) {
                $options[] = $option[0];
            }
        }

        $found[$name] = [
            'class' => $class,
            'options' => $options,
            'secondFile' => str_contains($source, '$this->files->put('),
        ];
    }

    ksort($found);

    return $generators = $found;
}

/**
 * The two numbers `firefly:cache` decides, derived by RUNNING it.
 *
 * `artifacts` is what the command prints as its manifest count — `count($report->files)` — and `pairs` is how
 * many scanner/compiler calls `writeManifests()` makes to produce them. Both are read from the writer itself,
 * because both are the kind of figure a chapter freezes into a console block and then never revisits: the
 * manuscripts printed `wrote 8 manifest(s)` and `wrote 12 manifest(s)` in two different chapters while the
 * command had long since settled at fourteen, and a reader comparing the page against their own terminal
 * found the book wrong with nothing going red.
 *
 * The count is taken by invoking the real writer over an EMPTY PSR-4 map into a throwaway directory, which is
 * exactly the claim the prose makes — `ManifestCacheWriter` writes every artifact unconditionally, so an
 * application with no `#[Scheduled]` method still gets an empty `scheduled.php` — and so the figure is
 * independent of whatever the repository happens to contain. If that ever stops being true, the number moves
 * and every sentence quoting it turns red, which is the point.
 *
 * @return array{artifacts: int, pairs: int}
 */
function fireflyCacheFigures(): array
{
    /** @var array{artifacts: int, pairs: int}|null $figures */
    static $figures = null;

    if ($figures !== null) {
        return $figures;
    }

    $dir = sys_get_temp_dir().'/firefly-docs-cache-'.bin2hex(random_bytes(6));

    try {
        $artifacts = count((new ManifestCacheWriter)->write([], $dir)->files);
    } finally {
        fireflyRemoveDirectory($dir);
    }

    $source = (string) file_get_contents(dirname(__DIR__).'/packages/cli/src/Cache/ManifestCacheWriter.php');

    return $figures = [
        'artifacts' => $artifacts,
        'pairs' => (int) preg_match_all('/\(new \w+Compiler\)->write\(/', $source),
    ];
}

/**
 * Deletes a throwaway directory and everything under it.
 *
 * Only ever called with a path this file just created under the system temp directory.
 */
function fireflyRemoveDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($dir);
}

it('pins every whitelist enumeration to the functions SecurityExpressionEvaluator really dispatches', function () {
    // DERIVED, not typed out. Every public bool method of the expression root is a candidate; the evaluator's
    // own parse() — which validates the name against dispatch()'s match with no root attached — is what
    // decides. Add hasIpAddress() to both and this list grows on its own, and the documents owe it a mention.
    $evaluator = new SecurityExpressionEvaluator;
    $whitelist = [];
    foreach ((new ReflectionClass(SecurityExpressionRoot::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $returnType = $method->getReturnType();
        if ($method->isConstructor() || ! $returnType instanceof ReflectionNamedType || $returnType->getName() !== 'bool') {
            continue;
        }

        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            // A string parameter takes a literal; hasPermission()'s `mixed $target` takes a #param reference.
            $arguments[] = $type instanceof ReflectionNamedType && $type->getName() === 'string' ? "'X'" : '#id';
        }

        try {
            $evaluator->parse($method->getName().'('.implode(',', $arguments).')');
        } catch (Throwable) {
            // The root offers it, the grammar does not: not part of the whitelist the documents describe.
            continue;
        }

        $whitelist[] = $method->getName();
    }

    expect($whitelist)->toContain('hasRole', 'hasAnyRole', 'hasAnyAuthority', 'hasAnyScope', 'denyAll');

    // The other direction, so "and nothing else" is a checked claim and not a hope: Spring names these and
    // this evaluator does not implement them. A document may not offer one to a reader.
    $absent = ['isAnonymous', 'isFullyAuthenticated', 'isRememberMe', 'hasIpAddress', 'principal', 'authentication'];
    foreach ($absent as $name) {
        expect(fn () => $evaluator->parse($name."('X')"))->toThrow(ExpressionParseException::class);
    }

    // A paragraph that says "whitelist tokenizer" AND starts naming functions has taken on the whole list:
    // every one of these enumerations closes with "and nothing else" / "only", so a short list is a false
    // one. A page that merely says the evaluator is a closed whitelist (docs/laravel-comparison.md) names no
    // function and owes none.
    //
    // THE TRIGGER IS BILINGUAL, because the manuscript is. `book/src-es/10-security.md` makes the identical
    // claim in Spanish and carries the identical list — the function names are code either way — so a trigger
    // that only knew the English phrase would walk the Spanish chapter and check nothing in it, which is the
    // shape of the bug this whole test exists to stop rather than to repeat.
    $triggers = ['whitelist tokenizer', 'tokenizador de lista blanca', 'lista blanca cerrada'];

    $enumerations = 0;
    $counted = 0;
    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            $triggered = array_filter($triggers, static fn (string $phrase): bool => stripos($paragraph, $phrase) !== false);
            if ($triggered === []) {
                continue;
            }

            // THE COUNT IS ITS OWN CLAIM, and it is the one that actually went wrong. Both book chapters said
            // "Eight function names, total" / "Ocho nombres de función, en total" and "8 functions total" in
            // their recap tables, months after `hasScope`/`hasAnyScope` landed — a number a reader can act on
            // (do not bother writing hasScope, the tokenizer cannot reach it) with nothing anywhere holding it
            // to the match it describes. A list can be checked by diffing it; a number has to be read, so it
            // is read here: any "<n> function(s)" / "<n> nombres de función" / "<n> funciones" in a paragraph
            // that has already claimed the whitelist is closed must be count($whitelist), spelled as a digit
            // or as the word for it in either language.
            preg_match_all(
                '/([\p{L}0-9]+)\s+(?:function names|functions?|nombres de función|funciones)\b/iu',
                $paragraph,
                $written,
            );
            foreach ($written[1] as $quantity) {
                $value = fireflyWrittenNumber($quantity);
                if ($value === null) {
                    continue; // "these functions", "the ten functions" — not a count being asserted.
                }

                $counted++;
                expect($value)->toBe(count($whitelist), sprintf(
                    '%s counts the closed whitelist at %s, and SecurityExpressionEvaluator::dispatch() '
                    .'reaches %d: %s.',
                    $page,
                    $quantity,
                    count($whitelist),
                    implode(', ', $whitelist),
                ));
            }

            $named = array_values(array_filter(
                $whitelist,
                static fn (string $name): bool => preg_match('/`'.$name.'[`(]/', $paragraph) === 1,
            ));
            if (count($named) < 2) {
                continue;
            }

            $enumerations++;
            $missing = array_values(array_diff($whitelist, $named));
            expect($missing)->toBe([], sprintf(
                '%s enumerates the expression whitelist and claims it is closed, but omits %s. '
                .'SecurityExpressionEvaluator::dispatch() accepts all of: %s.',
                $page,
                implode(', ', $missing),
                implode(', ', $whitelist),
            ));

            expect(str_contains($paragraph, '#param'))
                ->toBeTrue(sprintf('%s omits the #param references from the whitelist.', $page));

            foreach ($absent as $name) {
                expect(str_contains($paragraph, '`'.$name))->toBeFalse(sprintf(
                    '%s offers `%s` in the whitelist, and the evaluator refuses it with ExpressionParseException.',
                    $page,
                    $name,
                ));
            }
        }
    }

    // README.md, docs/architecture.md, docs/modules/security.md and, since this guard learned to read the
    // manuscript, each book chapter's prose and its recap table in both languages. If a rewrite drops one,
    // this test would otherwise pass by checking nothing at all.
    expect($enumerations)->toBe(7);

    // And the four counts those chapters write — the two that said eight. A rewrite that drops the number
    // instead of correcting it is a rewrite this canary makes visible.
    expect($counted)->toBe(4);
});

it('pins every 404-until-exposed claim and every actuator inventory to the endpoints the tree really mounts', function () {
    $root = dirname(__DIR__);

    // The default include list, read off the model an application without any firefly.management config gets.
    $model = ExposureModel::fromConfig(new Config(new ConfigRepository([])));
    /** @var list<string> $defaults */
    $defaults = (new ReflectionProperty(ExposureModel::class, 'include'))->getValue($model);
    expect($defaults)->not->toBe([]);

    // Every id the framework actually mounts, read off the endpoint implementations themselves.
    $ids = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/packages', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/src/')) {
            continue;
        }
        if (preg_match('/function endpointId\(\): string[^}]*?return\s+\'([a-z0-9]+)\'/s', (string) file_get_contents($file->getPathname()), $found) === 1) {
            $ids[] = $found[1];
        }
    }
    sort($ids);
    expect($ids)->toContain('health', 'info', 'env', 'beans', 'metrics', 'prometheus');

    // Exactly the default pair answers on a fresh install; everything else is a 404 until it is named.
    foreach ($ids as $id) {
        expect($model->isExposed($id))->toBe(in_array($id, $defaults, true), sprintf(
            '/actuator/%s is %s with no configuration, which the default include list (%s) contradicts.',
            $id,
            $model->isExposed($id) ? 'reachable' : 'a 404',
            implode(',', $defaults),
        ));
    }

    // A paragraph that reaches for the exposure key and says 404 must scope that 404, because it is false of
    // the default pair. Two of the accepted scopings are BUILT from $defaults, so the day the default
    // include list changes, the documents that spell it out fail here instead of quietly going stale.
    //
    // The last one is built from the OTHER side of the same list, and the book is why it exists: chapter 11's
    // exercise 4 tells a reader to leave the exposure list alone and confirm that `GET /actuator/beans`
    // returns a 404. That sentence is true and it is already scoped — to `beans`, by name — and demanding it
    // also recite "sensitive" or "health,info" would be asking a correct instruction to carry a hedge it does
    // not need. So naming a specific endpoint that really is unexposed by default counts as scoping it, and
    // the set of those is read off the tree rather than typed: promote one into the default include list and
    // every paragraph that leaned on its name to make the claim stops being scoped by it.
    $scopings = [
        'sensitive',
        'every other endpoint',
        '`'.implode('` and `', $defaults).'`',   // "`health` and `info`"
        implode(',', $defaults),                 // "health,info"
    ];

    foreach ($ids as $id) {
        if (! in_array($id, $defaults, true)) {
            $scopings[] = '/actuator/'.$id;
        }
    }

    $claims = 0;
    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (! str_contains($paragraph, 'exposure.include') || preg_match('/404|unexposed/i', $paragraph) !== 1) {
                continue;
            }

            $claims++;
            $scoped = array_filter($scopings, static fn (string $scoping): bool => stripos($paragraph, $scoping) !== false);
            expect($scoped)->not->toBe([], sprintf(
                '%s says an endpoint is a 404 until named in the exposure list without exempting %s, which '
                .'ExposureModel::fromConfig() exposes with no configuration at all.',
                $page,
                implode(' and ', $defaults),
            ));
        }
    }

    expect($claims)->toBeGreaterThanOrEqual(4);

    // ── The inventory, which is the other exhaustive claim the same $ids can settle ─────────────────────────
    //
    // Both first-impression documents now count the actuator out loud — README.md's roadmap bullet opens
    // "Fifteen ship today" and lists all fifteen paths, docs/architecture.md says "Fifteen `ActuatorEndpoint`
    // implementations ship" and lists the same fifteen ids — and until this block nothing read either one.
    // That is the worst kind of number to leave unguarded: a SIXTEENTH endpoint makes both sentences false
    // with no edit to either file and no failing test anywhere, and the README's very next bullet names
    // `/refresh`, `/threaddump` and `/shutdown` as the ones still to come, so the sixteenth is planned.
    //
    // $ids is already the whole truth, read off every endpointId() in the tree a few lines above, so the
    // check costs nothing: the number the sentence writes must be count($ids), and every id must be in it.
    // The trigger is the counting clause itself, not the word "ActuatorEndpoint" — the book implements that
    // interface in two listings and names it in a recap row without ever claiming to have the whole list, and
    // a paragraph that makes no inventory claim owes no inventory.
    $inventories = 0;
    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            $plain = str_replace(['`', '**'], '', $paragraph);
            $counted = preg_match(
                '/([\p{L}\d]+)\s+(?:ActuatorEndpoint\s+implementations?\s+ship|(?:actuator\s+)?endpoints?\s+ship|ship\s+today)/iu',
                $plain,
                $written,
            );
            if ($counted !== 1) {
                continue;
            }

            $quantity = fireflyWrittenNumber($written[1]);
            if ($quantity === null) {
                continue; // "More endpoints ship as the framework grows" — a promise, not a count.
            }

            $inventories++;
            expect($quantity)->toBe(count($ids), sprintf(
                '%s counts the shipped actuator endpoints at %s, and packages/*/src mounts %d of them: %s.',
                $page,
                $written[1],
                count($ids),
                implode(', ', $ids),
            ));

            $unnamed = array_values(array_filter(
                $ids,
                static fn (string $id): bool => preg_match('/\b'.$id.'\b/', $plain) !== 1,
            ));
            expect($unnamed)->toBe([], sprintf(
                '%s says how many actuator endpoints ship and then lists them, but never names %s — each of '
                .'which has an endpointId() under packages/*/src.',
                $page,
                implode(', ', $unnamed),
            ));
        }
    }

    // README.md's roadmap bullet and docs/architecture.md's observability section. A rewrite that drops the
    // count instead of correcting it lands here rather than shipping an inventory nothing reads.
    expect($inventories)->toBe(2);
});

it('pins every translator enumeration to the exceptions PersistenceExceptionTranslator really builds', function () {
    // DERIVED: the private build() is the whole mapping, and DriverErrorTable's public string constants are
    // the whole set of kinds it is ever handed. A ninth kind, or a kind rewired to another type, lands here.
    $build = new ReflectionMethod(PersistenceExceptionTranslator::class, 'build');
    $cause = new RuntimeException('driver said no');

    $produced = [];
    foreach ((new ReflectionClass(DriverErrorTable::class))->getConstants(ReflectionClassConstant::IS_PUBLIC) as $kind) {
        if (! is_string($kind)) {
            continue; // DRIVER_CODES and the SQLSTATE tables, not a kind.
        }
        $translated = $build->invoke(null, $kind, $cause, null);
        if (! $translated instanceof Throwable) {
            throw new RuntimeException(sprintf('build() answered %s for kind %s.', get_debug_type($translated), $kind));
        }
        $produced[] = basename(str_replace('\\', '/', $translated::class));
    }

    // The default arm: a code neither table classifies.
    $generic = $build->invoke(null, null, $cause, null);
    if (! $generic instanceof Throwable) {
        throw new RuntimeException(sprintf('build() answered %s for an unclassified code.', get_debug_type($generic)));
    }
    $produced[] = basename(str_replace('\\', '/', $generic::class));
    $produced = array_values(array_unique($produced));

    // The rest of the kernel family. Whatever is NOT in $produced cannot be attributed to a driver code —
    // this is the set the shipped sentence got wrong, and it is read off the directory, not typed out.
    $family = [];
    foreach ((array) glob($root = dirname(__DIR__).'/packages/kernel/src/Exception/Infrastructure/*.php') as $path) {
        $family[] = basename((string) $path, '.php');
    }
    $neverTranslated = array_values(array_diff($family, $produced));
    expect($neverTranslated)->toContain('OptimisticLockingFailureException', 'EmptyResultDataAccessException');

    // Where the two most quotable of them DO come from, proven from the sources rather than from the prose.
    $parents = class_parents(OptimisticLockException::class);
    expect($parents === false ? [] : array_values($parents))->toContain(OptimisticLockingFailureException::class);
    $trait = (string) file_get_contents((string) (new ReflectionClass(HasOptimisticLock::class))->getFileName());
    expect($trait)->toContain('new OptimisticLockException(');

    $raisedIn = static function (string $method, string $exception): void {
        $reflected = new ReflectionMethod(EloquentRepository::class, $method);
        $lines = file((string) $reflected->getFileName());
        $body = implode('', array_slice($lines === false ? [] : $lines, $reflected->getStartLine() - 1, $reflected->getEndLine() - $reflected->getStartLine() + 1));
        expect($body)->toContain('new '.$exception.'(');
    };
    $raisedIn('getById', 'EmptyResultDataAccessException');
    $raisedIn('findOneByExample', 'IncorrectResultSizeDataAccessException');

    // A paragraph that names the translator and four or more of the types it builds has taken on the list.
    $enumerations = 0;
    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (! str_contains($paragraph, 'PersistenceExceptionTranslator')) {
                continue;
            }

            $named = array_values(array_filter(
                $produced,
                static fn (string $type): bool => str_contains($paragraph, '`'.$type.'`'),
            ));
            if (count($named) < 4) {
                continue; // A passing mention ("a DuplicateKeyException instead of a QueryException") owes nothing.
            }

            $enumerations++;
            $missing = array_values(array_diff($produced, $named));
            expect($missing)->toBe([], sprintf(
                '%s enumerates what PersistenceExceptionTranslator produces but omits %s.',
                $page,
                implode(', ', $missing),
            ));

            // A type the translator never builds may still appear — but only beside the seam that really
            // raises it, never inside the enumeration as a product of a SQLSTATE.
            $companions = [
                'OptimisticLockingFailureException' => 'OptimisticLockException',
                'EmptyResultDataAccessException' => 'getById',
                'IncorrectResultSizeDataAccessException' => 'findOneByExample',
            ];
            foreach ($neverTranslated as $type) {
                if (! str_contains($paragraph, '`'.$type.'`')) {
                    continue;
                }
                expect(str_contains($paragraph, $companions[$type] ?? $type))->toBeTrue(sprintf(
                    '%s lists %s among the translator\'s output; build() never returns it.',
                    $page,
                    $type,
                ));
            }
        }
    }

    // docs/architecture.md and docs/modules/data.md.
    expect($enumerations)->toBe(2);

    // The architecture page additionally pins the gate and the seams, because the bullet reads as if every
    // database error in the process were translated. Both halves are checked against firefly/data itself.
    $architecture = (string) file_get_contents(dirname(__DIR__).'/docs/architecture.md');
    expect(file_get_contents(dirname(__DIR__).'/packages/data/src/DataSettings.php'))
        ->toContain("'firefly.data.exception-translation.enabled'");
    expect($architecture)->toContain('firefly.data.exception-translation.enabled');

    $seams = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__).'/packages/data/src', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php'
            && str_contains((string) file_get_contents($file->getPathname()), '->translate(')) {
            $seams[] = $file->getBasename('.php');
        }
    }
    sort($seams);
    expect($seams)->toBe(['EloquentRepository', 'TransactionTemplate']);
    foreach ($seams as $seam) {
        expect(str_contains($architecture, $seam))->toBeTrue(sprintf(
            'docs/architecture.md describes where exception translation applies without naming %s, which calls translate().',
            $seam,
        ));
    }
});

it('pins every prefersHtml() paragraph to the order and the media types ErrorPageRenderer really checks', function () {
    // The fourth kind of wrong sentence, and the most expensive: one that states a fact about the FRAMEWORK
    // BELOW correctly and then draws the opposite conclusion from it. The shipped architecture bullet read
    // "Laravel tests the *first* acceptable type, so `Accept: application/json, text/html` names `text/html`
    // yet is plainly a machine asking" — the premise true, the example its exact inverse. Under that first-
    // type rule the header's first type is `application/json`, so `wantsJson()` is TRUE, so prefersHtml()
    // has already answered false two checks before `json-paths` is consulted. The clause the paragraph
    // existed to defend was being defended by the one header it does nothing for, on the security path that
    // decides whether an anonymous API call gets a 401 problem document or a `302 /login`.
    //
    // DERIVED, so the guard cannot repeat the mistake it catches: the ORDER of the three checks is read off
    // the method body, the accepted media types are read off its return expression, and the verdicts are
    // taken from the real method over real requests. Nothing below types out an answer the source could
    // contradict.
    $reflected = new ReflectionMethod(ErrorPageRenderer::class, 'prefersHtml');
    $lines = file((string) $reflected->getFileName());
    $body = implode('', array_slice($lines === false ? [] : $lines, $reflected->getStartLine() - 1, $reflected->getEndLine() - $reflected->getStartLine() + 1));

    $at = static function (string $needle) use ($body): int {
        $offset = strpos($body, $needle);
        if ($offset === false) {
            throw new RuntimeException(sprintf('ErrorPageRenderer::prefersHtml() no longer contains %s.', $needle));
        }

        return $offset;
    };
    expect($at('wantsJson()'))->toBeLessThan($at('isJsonPath('), 'prefersHtml() now consults json-paths before wantsJson()')
        ->and($at('isJsonPath('))->toBeLessThan($at("headers->get('Accept'"), 'prefersHtml() now reads Accept before json-paths');

    preg_match_all("/str_contains\(\\\$accept, '([^']+)'\)/", $body, $matches);
    $accepted = $matches[1];
    sort($accepted);
    expect($accepted)->toBe(['application/xhtml+xml', 'text/html'], 'the media types prefersHtml() names have changed');

    // The real method, over real requests. `api/*` is ErrorPageSettings' own default; the empty list is the
    // control that isolates what `json-paths` contributes and what it does not.
    $verdict = static function (string $accept, string $path, bool $jsonPaths): bool {
        $request = Request::create('/'.$path, 'GET');
        $request->headers->set('Accept', $accept);

        return (new ErrorPageRenderer(new ErrorPageSettings(jsonPaths: $jsonPaths ? ['api/*'] : [])))->prefersHtml($request);
    };

    // A header whose FIRST acceptable type is JSON is a wantsJson() call, answered before json-paths is
    // reached — turning the list off changes nothing, which is the proof that the clause did not decide it.
    expect($verdict('application/json, text/html', 'api/orders', true))->toBeFalse()
        ->and($verdict('application/json, text/html', 'api/orders', false))->toBeFalse()
        ->and($verdict('application/json, text/html', 'orders/999999', false))->toBeFalse();

    // The reverse ordering is the one that needs the clause: wantsJson() is false, `text/html` is named, and
    // only the path says this URL is a machine surface.
    expect($verdict('text/html, application/json', 'api/orders', false))->toBeTrue()
        ->and($verdict('text/html, application/json', 'api/orders', true))->toBeFalse();

    // Every Accept header a prefersHtml() paragraph quotes has to be one the clause it illustrates can still
    // reach. A header wantsJson() already rejects cannot demonstrate anything about json-paths.
    $paragraphs = 0;
    foreach (fireflyProsePages() as $page => $pageParagraphs) {
        foreach ($pageParagraphs as $paragraph) {
            if (! str_contains($paragraph, 'prefersHtml')) {
                continue;
            }

            $paragraphs++;

            foreach ($accepted as $type) {
                expect(str_contains($paragraph, $type))->toBeTrue(sprintf(
                    '%s describes the prefersHtml() browser test without naming %s, which the method accepts '
                    .'exactly as it accepts the other.',
                    $page,
                    $type,
                ));
            }

            if (! str_contains($paragraph, 'json-paths')) {
                continue;
            }

            preg_match_all('/`Accept:\s*([^`]+)`/', $paragraph, $headers);
            foreach ($headers[1] as $header) {
                $probe = Request::create('/api/orders', 'GET');
                $probe->headers->set('Accept', $header);
                expect($probe->wantsJson())->toBeFalse(sprintf(
                    '%s offers `Accept: %s` as what json-paths catches, but its first acceptable type is %s, '
                    .'so wantsJson() is true and prefersHtml() answers false two checks earlier — json-paths '
                    .'is never consulted. Use a header whose first type is text/html.',
                    $page,
                    $header,
                    $probe->getAcceptableContentTypes()[0] ?? 'nothing',
                ));
            }
        }
    }

    // docs/architecture.md's entry-point bullet, and docs/modules/security.md's prose and its settings table.
    expect($paragraphs)->toBe(3);
});

it('pins every stereotype-inheritance sentence to the class hierarchy PHP really declares', function () {
    // The fifth kind of wrong sentence: one that states a real relation BACKWARDS. Both tutorials shipped
    // "The same route scan finds both (`#[RestController]` extends `#[Controller]`)" — the inverse of
    // `class Controller extends RestController`, and the inverse of the bullet three lines above it in the
    // same document, which says `#[RestController]` extends `#[Component]`. A reader who believed it would
    // conclude that an IS_INSTANCEOF filter on `#[Controller]` catches REST controllers, which is precisely
    // the wrong way round, and would mis-model which stereotype is the specialisation of which — the one
    // idea the whole stereotype design rests on.
    //
    // DERIVED: is_subclass_of() over the real attribute classes, and the failure prints the real chain, so
    // the message is the corrected sentence rather than a report that something is off.
    $attributes = fireflyAttributeClasses();

    expect($attributes)->toHaveKeys(['Component', 'Controller', 'RestController', 'Service', 'Repository']);

    $claims = 0;

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            // Deliberately narrow: BOTH sides bracketed and adjacent, which is the shape a claim about two
            // stereotypes takes. "It **extends** `#[RestController]`" — a pronoun subject, as
            // docs/modules/web.md writes it — is left to a human, because resolving that subject would be
            // this guard guessing at prose.
            preg_match_all(
                '/`#\[(\w+)\]`\s*(?:\*\*)?\s*(?:extends|extiende)\s*(?:\*\*)?\s*`#\[(\w+)\]`/u',
                $paragraph,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [$claim, $child, $parent]) {
                if (! isset($attributes[$child], $attributes[$parent])) {
                    continue;
                }

                $claims++;

                $chain = [];
                for ($class = $attributes[$child]; $class !== false; $class = get_parent_class($class)) {
                    $chain[] = $class;
                }

                expect(is_subclass_of($attributes[$child], $attributes[$parent]))->toBeTrue(sprintf(
                    '%s writes "%s", but the real hierarchy is %s. The sentence is inverted: swap the two '
                    .'names, and remember that an IS_INSTANCEOF filter on the PARENT is what finds the child.',
                    $page,
                    preg_replace('/\s+/', ' ', $claim),
                    implode(' extends ', $chain),
                ));
            }
        }
    }

    // docs/tutorial.md and docs/tutorial.es.md, the two pages that name both stereotypes in one breath.
    expect($claims)->toBe(2);
});

it('pins every Composer constraint table to what composer/semver really matches', function () {
    // The sixth: a sentence about a TOOL's semantics, which no amount of reading the framework can check.
    // docs/versioning.md said "`^26.09` allows any patch release within `26.09.x` but not a `26.10.x`
    // release" beside the very paragraph warning that a CalVer month bump is where an incompatible change is
    // allowed to land. Composer normalises `26.09` to `26.09.0.0` and expands a caret to `< 27.0.0.0`, so
    // `^26.09` accepts 26.10.x — the page was promising safety it did not provide, which is worse than
    // saying nothing.
    //
    // The fix that holds is to make the claim MACHINE-CHECKABLE: a table of constraint x version, every cell
    // answered here by Composer's own matcher. composer/semver is the same library Composer resolves with,
    // and it is in this tree because symplify/monorepo-builder — the tool the release runbook drives — pulls
    // composer/composer in. Prose either side of the table is free; the table is the load-bearing claim.
    $cells = 0;
    $tables = 0;

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            $rows = array_values(array_filter(
                array_map('trim', explode("\n", $paragraph)),
                static fn (string $line): bool => str_starts_with($line, '|'),
            ));

            if (count($rows) < 3) {
                continue;
            }

            $header = fireflyTableCells($rows[0]);

            if (($header[0] ?? '') !== 'Constraint' || count($header) < 2) {
                continue;
            }

            $versions = [];
            foreach (array_slice($header, 1) as $cell) {
                if (preg_match('/^`(\d{2}\.\d{2}\.\d+)`$/', $cell, $matched) !== 1) {
                    $versions = [];

                    break;
                }

                $versions[] = $matched[1];
            }

            // Every other `| Constraint |` table in the documentation (validation's sentence table,
            // openapi's schema table) has a prose column here and is not this table's business.
            if ($versions === []) {
                continue;
            }

            $tables++;

            foreach (array_slice($rows, 1) as $row) {
                if (preg_match('/^\|[\s:|-]+\|$/', $row) === 1) {
                    continue;
                }

                $columns = fireflyTableCells($row);

                $constraint = preg_match('/^`([^`]+)`$/', $columns[0] ?? '', $matched) === 1 ? $matched[1] : '';

                expect($constraint)->not->toBe('', sprintf(
                    '%s: a constraint table row must open with a backticked constraint, not "%s".',
                    $page,
                    $columns[0] ?? '',
                ));

                foreach ($versions as $index => $version) {
                    $cells++;

                    $expected = Semver::satisfies($version, $constraint) ? 'accepted' : 'rejected';

                    expect($columns[$index + 1] ?? '')->toBe($expected, sprintf(
                        '%s says `%s` %s %s, but Composer\'s own matcher says the opposite — composer/semver '
                        .'expands it to %s.',
                        $page,
                        $constraint,
                        $columns[$index + 1] ?? '(nothing)',
                        $version,
                        (string) (new VersionParser)->parseConstraints($constraint),
                    ));
                }
            }
        }
    }

    // docs/versioning.md's one table: three constraint shapes against three releases.
    expect($tables)->toBe(1)->and($cells)->toBe(9);
});

it('pins every make:firefly-* table to the flags and the file count the generators really have', function () {
    // The seventh, and the one a reader pays for immediately: a generator table that describes a command's
    // REPLACED behaviour. `make:firefly-controller` shipped a full REST resource plus the #[Valid]
    // #[RequestBody] DTO its store/update bind, with the old single-action shape moved behind `--plain`, and
    // the tables went on promising one file and never named the flag that would give it.
    //
    // Two derived contracts, both of which that row failed. A row must name every option its command
    // declares — an undocumented flag is a feature nobody can reach — and a row for a command that writes a
    // SECOND file must say so in a word a reader counts with, because "Two files" is the difference between
    // reviewing one generated file and finding two.
    $generators = fireflyGenerators();

    expect(array_keys($generators))->toBe([
        'make:firefly-component',
        'make:firefly-config-properties',
        'make:firefly-controller',
        'make:firefly-entity',
        'make:firefly-handler',
        'make:firefly-listener',
        'make:firefly-repository',
        'make:firefly-service',
    ]);

    $checked = 0;

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            foreach (explode("\n", $paragraph) as $line) {
                $line = trim($line);

                if (preg_match('/^\|\s*`(make:firefly-[\w*-]+)`\s*\|(.*)\|\s*$/u', $line, $matches) !== 1) {
                    continue;
                }

                [, $command, $description] = $matches;

                // `make:firefly-*` is how a summary table names the whole family, not one command.
                if (str_contains($command, '*')) {
                    continue;
                }

                expect(array_key_exists($command, $generators))->toBeTrue(sprintf(
                    '%s documents `%s`, which no Make*Command under packages/cli/src declares.',
                    $page,
                    $command,
                ));

                $checked++;

                foreach ($generators[$command]['options'] as $option) {
                    expect(str_contains($description, '--'.$option))->toBeTrue(sprintf(
                        '%s describes `%s` without naming its `--%s` flag, which %s declares — the row '
                        .'documents one shape of a command that has two.',
                        $page,
                        $command,
                        $option,
                        $generators[$command]['class'],
                    ));
                }

                if (! $generators[$command]['secondFile']) {
                    continue;
                }

                expect(preg_match('/\b(two|dos)\b/iu', $description))->toBe(1, sprintf(
                    '%s describes `%s` as though it wrote one file, but %s calls $this->files->put() itself, '
                    .'on top of the write GeneratorCommand already performs. Say how many files the row means.',
                    $page,
                    $command,
                    $generators[$command]['class'],
                ));
            }
        }
    }

    // docs/cli.md, book/src/13-cli-cache.md and book/src-es/13-cli-cache.md, eight generators each.
    expect($checked)->toBe(24);
});

it('pins every firefly:cache figure to the artifacts ManifestCacheWriter really writes', function () {
    // The eighth, and the first one a reader checks against their own terminal. `firefly:cache` prints the
    // number of artifacts it wrote, and three documents quote that line as a worked example. Two of them
    // quoted it from a tree that no longer exists — `wrote 8 manifest(s)` in the DI chapter, `wrote 12` in
    // the CQRS chapter — while the command writes fourteen for EVERY application, unconditionally, which is
    // the whole reason the figure is quotable at all. A console block is a promise about what the reader will
    // see; these two were promising output the command cannot produce.
    //
    // The same drift reached the pair count. `writeManifests()` grew the #[ControllerAdvice] handler manifest
    // and the proxy plan, and four chapters went on calling a scanner "one of the ten pairs".
    //
    // AND IT REACHED THE STEP COUNT, which the first version of this guard could not see. Chapter 13 states
    // the same fact three times in three different shapes — a learning objective at the top, a sentence over
    // the artifact tree, a recap row at the bottom — and only the middle one says "lands N artifacts", the
    // one shape the patterns below matched. So the chapter was corrected to "Thirteen steps in total land
    // fourteen artifacts" and went on promising "the eleven scanner-and-compiler steps it runs" in its first
    // sentence and "(11 steps, 12 artifacts)" in its last table, in BOTH manuscripts: a reader met the wrong
    // number first and again last, 277 lines after the right one, with nothing red. A guard that only knows
    // one phrasing of a claim is a guard on that phrasing, not on the claim, so all three are matched here.
    $figures = fireflyCacheFigures();

    // Fourteen artifacts out of twelve scanner/compiler calls: the component+context compiler emits two, and
    // writeProxies() appends the proxies.php classmap. If this fails, the documents below are right and this
    // expectation is the thing to re-derive — but read ManifestCacheWriter first.
    expect($figures)->toBe(['artifacts' => 14, 'pairs' => 12]);

    // A STEP is not a pair: writeManifests() makes twelve scanner-then-compiler calls and writeProxies() is
    // the thirteenth step, which is why the chapter's own body says "twelve `scan()`-then-`write()` calls ...
    // plus one more step". Derived as pairs + 1 rather than typed, so adding a capability moves the pair
    // count, the step count and the sentences that quote either of them together.
    $steps = $figures['pairs'] + 1;

    $consoleLines = 0;
    $pairClaims = 0;
    $artifactClaims = 0;
    $stepClaims = 0;
    $recapClaims = 0;

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (preg_match_all('/\bwrote\s+(\d+)\s+manifest\(s\)/u', $paragraph, $matches) > 0) {
                foreach ($matches[1] as $written) {
                    $consoleLines++;

                    expect((int) $written)->toBe($figures['artifacts'], sprintf(
                        '%s prints `firefly:cache — wrote %s manifest(s)`, which the command cannot produce: '
                        .'ManifestCacheWriter writes %d artifacts for every application, unconditionally.',
                        $page,
                        $written,
                        $figures['artifacts'],
                    ));
                }
            }

            // "one of the **twelve** scanner/compiler pairs", "uno de los doce pares escáner/compilador",
            // and the short form a chapter uses once it has named them: "one of its twelve pairs".
            $patterns = [
                '/(?:\*\*)?(\p{L}+)(?:\*\*)?\s+(?:scanner\/compiler\s+pairs|pares\s+(?:escáner|scanner)\/(?:compilador|compiler))/u',
                '/\b(?:its|sus)\s+(?:\*\*)?(\p{L}+)(?:\*\*)?\s+(?:pairs|pares)\b/u',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $paragraph, $matches) < 1) {
                    continue;
                }

                foreach ($matches[1] as $token) {
                    $written = fireflyWrittenNumber($token);

                    if ($written === null) {
                        continue;
                    }

                    $pairClaims++;

                    expect($written)->toBe($figures['pairs'], sprintf(
                        '%s calls it one of %s scanner/compiler pairs; ManifestCacheWriter::writeManifests() '
                        .'makes %d such calls.',
                        $page,
                        $token,
                        $figures['pairs'],
                    ));
                }
            }

            if (preg_match_all('/\b(?:lands?|dejan?)\s+(?:\*\*)?(\p{L}+)(?:\*\*)?\s+(?:artifacts|artefactos)\b/u', $paragraph, $matches) > 0) {
                foreach ($matches[1] as $token) {
                    $written = fireflyWrittenNumber($token);

                    if ($written === null) {
                        continue;
                    }

                    $artifactClaims++;

                    expect($written)->toBe($figures['artifacts'], sprintf(
                        '%s says the run lands %s artifacts under bootstrap/cache/firefly/; it lands %d.',
                        $page,
                        $token,
                        $figures['artifacts'],
                    ));
                }
            }

            // "the thirteen scanner-and-compiler steps it runs", "los trece pasos de escáner-y-compilador",
            // and the sentence over the artifact tree: "Thirteen steps in total", "Trece pasos en total".
            // `pair` is deliberately NOT an alternative here — chapter 13 calls writeManifests()'s twelve
            // calls "scanner-and-compiler pair"s one line above, and that count is the pair patterns' job.
            $stepPatterns = [
                '/(?:\*\*)?(\p{L}+)(?:\*\*)?\s+(?:scanner-and-compiler\s+steps|pasos\s+de\s+escáner-y-compilador)\b/u',
                '/(?:\*\*)?(\p{L}+)(?:\*\*)?\s+(?:steps\s+in\s+total|pasos\s+en\s+total)\b/u',
            ];

            foreach ($stepPatterns as $pattern) {
                if (preg_match_all($pattern, $paragraph, $matches) < 1) {
                    continue;
                }

                foreach ($matches[1] as $token) {
                    $written = fireflyWrittenNumber($token);

                    if ($written === null) {
                        continue;
                    }

                    $stepClaims++;

                    expect($written)->toBe($steps, sprintf(
                        '%s says firefly:cache runs %s steps; ManifestCacheWriter runs %d — '
                        .'%d scanner/compiler pairs plus writeProxies().',
                        $page,
                        $token,
                        $steps,
                        $figures['pairs'],
                    ));
                }
            }

            // The recap row, which states both figures at once in digits: "(13 steps, 14 artifacts)",
            // "(13 pasos, 14 artefactos)". A chapter's last table is the line a reader copies out.
            if (preg_match_all('/\((\d+)\s+(?:steps|pasos),\s+(\d+)\s+(?:artifacts|artefactos)\)/u', $paragraph, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $recapClaims++;

                    expect([(int) $match[1], (int) $match[2]])->toBe([$steps, $figures['artifacts']], sprintf(
                        '%s recaps firefly:cache as (%s steps, %s artifacts); it runs %d steps and lands %d.',
                        $page,
                        $match[1],
                        $match[2],
                        $steps,
                        $figures['artifacts'],
                    ));
                }
            }
        }
    }

    // docs/cli.md, both tutorials, and the DI and CQRS chapters in both languages; the pair count in chapters
    // 3, 7 (twice), 8 and 9 of each manuscript; the artifact tree in chapter 13 of each. The step count is
    // stated twice per manuscript — chapter 13's opening objective and the line over the artifact tree — and
    // the recap row once. These canaries are what turns a REWORDING red: a sentence that stops matching stops
    // being checked, silently, and that is precisely how "(11 steps, 12 artifacts)" survived the correction
    // of the paragraph 277 lines above it.
    expect($consoleLines)->toBe(7)
        ->and($pairClaims)->toBe(10)
        ->and($artifactClaims)->toBe(2)
        ->and($stepClaims)->toBe(4)
        ->and($recapClaims)->toBe(2);
});

it('pins the capabilities `--with` really fetches to the metapackage manifest', function () {
    // The ninth. `docs/installation.md` explained `--with` with one flat sentence — "every non-adapter
    // capability is already required by the `firefly/firefly` metapackage, so naming one promotes an
    // installed package to an explicit dependency rather than fetching anything new" — and a reader who
    // believed it ran `firefly new --with=testing` expecting nothing to be downloaded. firefly/testing is
    // deliberately OUTSIDE the metapackage (it pulls orchestra/testbench), so that install fetches a package
    // and writes it into require-dev. The sentence was inherited from CapabilityCatalog's own docblock, and
    // `docs/getting-started.md` said the opposite two pages away.
    //
    // The real invariant is the one tests/MetapackageCoverageTest.php asserts: every non-adapter, NON-DEV
    // capability is required by the metapackage. What is left over — the brokers an application chooses for
    // itself, plus the dev-only test kit — is exactly the set `--with` really installs, and it is computable,
    // so the page is held to it rather than to a list somebody kept in their head. `scheduling-postgres` is
    // why this is derived and not typed: it is an `adapter: true` capability that the metapackage DOES ship,
    // because an advisory-lock backend needs nothing but a Postgres connection, so "adapter" and "fetched"
    // are not the same set and a hand-written caveat gets that wrong in the obvious direction.
    /** @var mixed $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__).'/packages/firefly/composer.json'),
        true,
    );
    $require = is_array($manifest) && is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
    $shipped = array_map(strval(...), array_keys($require));

    expect($shipped)->not->toBeEmpty('packages/firefly/composer.json requires nothing at all');

    $fetched = [];
    $shippedAdapters = [];

    foreach (CapabilityCatalog::all() as $capability) {
        if (! in_array($capability->package, $shipped, true)) {
            $fetched[$capability->id] = $capability;
        } elseif ($capability->adapter) {
            $shippedAdapters[] = $capability->id;
        }
    }

    $prose = (string) preg_replace(
        '/\s+/',
        ' ',
        (string) file_get_contents(dirname(__DIR__).'/docs/installation.md'),
    );

    // The count, as the page writes it: "Four capabilities are the exception".
    $found = preg_match('/(?:\*\*)?(\p{L}+)(?:\*\*)?\s+capabilities\s+are\s+the\s+exception/u', $prose, $match) === 1;

    expect($found)->toBeTrue('docs/installation.md no longer counts the capabilities --with really fetches');

    $counted = $match[1] ?? '';

    expect(fireflyWrittenNumber($counted))->toBe(count($fetched), sprintf(
        'docs/installation.md says %s capabilities are fetched by --with; the metapackage leaves %d out of '
        .'its require block: %s.',
        $counted,
        count($fetched),
        implode(', ', array_keys($fetched)),
    ));

    $drifted = [];

    foreach ($fetched as $id => $capability) {
        if (! str_contains($prose, '`'.$id.'`')) {
            $drifted[] = $id.' is fetched by --with and the page never names it';
        }

        // A dev-only capability lands somewhere else in the generated manifest, which is the part a reader
        // acts on: ArchetypeApplier writes it under require-dev, not require.
        if ($capability->dev && ! str_contains($prose, '**`require-dev`**')) {
            $drifted[] = $id.' is dev-only and the page no longer says it lands in require-dev';
        }
    }

    // The other direction, and the one a hand-written caveat gets wrong: an adapter the metapackage DOES
    // ship must still be named, or "the adapters are fetched" quietly becomes true of one package too many.
    foreach ($shippedAdapters as $id) {
        if (! str_contains($prose, '`'.$id.'`')) {
            $drifted[] = $id.' is an adapter the metapackage ships, and the page no longer says so';
        }
    }

    expect($drifted)->toBe([], implode('; ', $drifted));
});

it('pins the contributing guide\'s documentation-gate roster to the test files it names', function () {
    // The tenth, and the one closest to home: it guards the page that explains the guards. The Documentation
    // section opened "held to the same gate as code, by five Pest tests" and then named five — while seven
    // ran in the same `composer check`. tests/ReadmeDocLinksTest.php and tests/SiteNavigationTest.php were
    // documented nowhere, and one of SiteNavigationTest's five tests is itself a prose-count guard, so a
    // contributor who broke a quoted count met a red run from a test the guide had never mentioned. That is
    // the defect this whole wave exists to close, reproduced inside the wave's own documentation.
    //
    // WHAT THIS CAN AND CANNOT SEE. It derives the roster from the section's own text and pins two things a
    // human gets wrong for free: the COUNT (a number typed beside a list is a number that stops matching the
    // list) and the EXISTENCE of every file named (a renamed test leaves a citation pointing at nothing). It
    // cannot know that an eighth documentation guard was added and left unnamed — nothing in tests/ marks a
    // file as belonging to this gate, and inventing a marker to make a sentence checkable would be inventing
    // a mechanism for the documentation's benefit. Adding a guard therefore still means writing a sentence
    // about it; this is what stops the sentence and the number from drifting apart afterwards.
    $guide = (string) file_get_contents(dirname(__DIR__).'/docs/contributing.md');

    $found = preg_match('/\n## Documentation\n(.*?)(?=\n## )/s', $guide, $section) === 1;

    expect($found)->toBeTrue('docs/contributing.md no longer has a `## Documentation` section');

    $prose = (string) preg_replace('/\s+/', ' ', $section[1] ?? '');

    preg_match_all('/tests\/[A-Za-z0-9]+Test\.php/', $prose, $named);
    $roster = array_values(array_unique($named[0]));
    sort($roster);

    expect($roster)->not->toBeEmpty('the Documentation section names no Pest test at all');

    $missing = array_values(array_filter(
        $roster,
        static fn (string $path): bool => ! is_file(dirname(__DIR__).'/'.$path),
    ));

    expect($missing)->toBe([], 'the Documentation section cites a test that does not exist: '.implode(', ', $missing));

    $counts = preg_match('/by\s+(?:\*\*)?(\p{L}+)(?:\*\*)?\s+Pest\s+tests\b/u', $prose, $match) === 1;

    expect($counts)->toBeTrue('the Documentation section no longer says how many Pest tests hold the gate');

    $quoted = $match[1] ?? '';

    expect(fireflyWrittenNumber($quoted))->toBe(count($roster), sprintf(
        'docs/contributing.md says the documentation gate is %s Pest tests and then names %d: %s.',
        $quoted,
        count($roster),
        implode(', ', $roster),
    ));
});
