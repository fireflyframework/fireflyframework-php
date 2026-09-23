<?php

// tests/DocsProseIsRealTest.php

declare(strict_types=1);

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Actuator\Health\DbHealthIndicator;
use Firefly\Actuator\Introspection\SensitiveValueMasker;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\Data\DataFilter;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Config\Config;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Data\Exception\DriverErrorTable;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Locking\HasOptimisticLock;
use Firefly\Data\Repository\Locking\OptimisticLockException;
use Firefly\Installer\CapabilityCatalog;
use Firefly\Kernel\Exception\Infrastructure\OptimisticLockingFailureException;
use Firefly\Observability\HttpExchanges\HeaderMasker;
use Firefly\OpenApi\OpenApiProperties;
use Firefly\Security\Access\Expression\ExpressionParseException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\OAuth2\Client\Discovery\OidcDiscovery;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\CommonOAuth2Provider;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientProperties;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientPropertiesMapper;
use Firefly\Tests\Support\DocsCodeAudit;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
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

/**
 * Every DbHealthIndicator sentence in the manuscript, judged against a given `matchIfMissing`.
 *
 * Split out of the guard below so the guard's own PROMISE is testable. The promise is that both rules are
 * *conditional on the indicator still being on by default*: the day somebody makes it opt-in again, the
 * sentences these rules refuse become the true ones, and a documentation test that went red at that moment
 * would be blaming the book for a decision the framework made. Taking `$onByDefault` as a parameter is what
 * lets the guard assert that — it runs the scan a second time with the opposite answer and checks that the
 * refusals really do fall silent, rather than trusting a docblock that says they do.
 *
 * @return array{quoting: int, failures: list<string>}
 */
function fireflyDbHealthIndicatorProse(bool $onByDefault): array
{
    $quoting = 0;
    $failures = [];

    if (! $onByDefault) {
        return ['quoting' => $quoting, 'failures' => $failures];
    }

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (! str_contains($paragraph, 'DbHealthIndicator')) {
                continue;
            }

            $where = $page.': '.mb_substr((string) preg_replace('/\s+/', ' ', trim($paragraph)), 0, 140);
            $namesTheDefault = str_contains($paragraph, 'matchIfMissing: true');

            // A paragraph that QUOTES the condition must quote the value it really carries. "#[ConditionalOnProperty]
            // with no matchIfMissing" was the sentence that shipped, in two languages, beside the attribute that has it.
            if (str_contains($paragraph, 'ConditionalOnProperty')) {
                $quoting++;

                if (! $namesTheDefault) {
                    $failures[] = $where;
                }
            }

            // And a paragraph that calls the indicator optional, in either language, has to be EXPLAINING the
            // mechanism rather than asserting the retired default — so it must also name one of the two things
            // that make it on-by-default-and-still-silent-without-a-database. The recap rows said "opt-in
            // `SELECT 1` DB check" and named neither.
            $callsItOptional = str_contains($paragraph, 'opt-in') || str_contains($paragraph, 'opcional');

            if ($callsItOptional && ! $namesTheDefault && ! str_contains($paragraph, 'ConditionalHealthIndicator')) {
                $failures[] = $where;
            }
        }
    }

    return ['quoting' => $quoting, 'failures' => $failures];
}

/**
 * The words one masking regex alternates over, read off the class by reflection and sorted.
 *
 * Reflection rather than a literal, because the list IS the thing under test: a copy of it in this file would
 * be a second place for the same enumeration to go stale, which is exactly the failure the guard that calls
 * this one exists to refuse. An empty result means the constant is no longer a pattern this can read, and the
 * caller turns that into a named failure rather than a silently passing check.
 *
 * @param  class-string  $class
 * @return list<string>
 */
function fireflyMaskingWords(string $class): array
{
    $pattern = (new ReflectionClassConstant($class, 'SENSITIVE'))->getValue();

    if (! is_string($pattern)) {
        return [];
    }

    $bare = trim((string) preg_replace('#^/|/[a-z]*$#', '', $pattern));
    $split = explode('|', $bare);
    sort($split);

    return $split;
}

/**
 * Every paragraph that presents the OAuth2 client presets and says what one of them settles, held against the
 * two facts the package really carries: WHICH presets name a client-authentication method, and what the mapper
 * deduces for the ones that do not.
 *
 * @param  list<string>  $builtIn  preset ids whose registration() names a `client_authentication_method`
 * @param  list<string>  $deduced  preset ids that leave the method to the registration
 * @param  list<string>  $outcomes  the methods OAuth2ClientPropertiesMapper really produces for a $deduced preset
 * @return array{quoting: int, failures: list<string>}
 */
function fireflyOAuth2PresetProse(array $builtIn, array $deduced, array $outcomes): array
{
    $quoting = 0;
    $failures = [];

    // The rule exists only while the two sets are both inhabited. If every preset named a method, "the presets'
    // client-authentication method is built in" would be true as written and there would be nothing to qualify;
    // if none did, no page would be attributing one to them. Either way this walk must refuse nothing and hold
    // nothing — the promise the guard below exercises rather than describes.
    if ($builtIn === [] || $deduced === [] || $outcomes === []) {
        return ['quoting' => $quoting, 'failures' => $failures];
    }

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            $lower = mb_strtolower($paragraph);

            // The trigger is narrow on purpose: the paragraph has to be presenting the presets AS A SET — the
            // word plus every id, so a page may name `google` or quote one registration in passing without
            // owing the whole rule — and it has to be saying something about the client-authentication method.
            if (! str_contains($lower, 'preset')) {
                continue;
            }

            foreach ([...$builtIn, ...$deduced] as $id) {
                if (! str_contains($lower, $id)) {
                    continue 2;
                }
            }

            if (preg_match('/client[-_ ]authentication[-_ ]method|m[eé]todo de autenticaci[oó]n de cliente/u', $lower) !== 1) {
                continue;
            }

            $quoting++;

            // Then it owes the derivation, in full. A paragraph that lists the method among what a preset
            // supplies and stops there tells a reader that `keycloak` comes with one; the only way to be
            // reading a true paragraph is to find, in it, every method the mapper really lands on when the
            // preset supplies none — which is why these are read off the mapper rather than typed here.
            foreach ($outcomes as $outcome) {
                if (preg_match('/\b'.preg_quote($outcome, '/').'\b/', $lower) === 1) {
                    continue;
                }

                $failures[] = $page.' credits the presets with a client-authentication method without saying that '
                    .implode(', ', $deduced).' leave it to the registration, where the mapper deduces `'.$outcome
                    .'`: '.mb_substr((string) preg_replace('/\s+/', ' ', trim($paragraph)), 0, 160);
            }
        }
    }

    return ['quoting' => $quoting, 'failures' => $failures];
}

/**
 * Every paragraph that presents the two OAuth2 packages and says what installing one costs, held against the
 * only file that can settle it: the `require` block of each package's composer.json.
 *
 * Three questions are asked, and none of the answers is typed out here. Does the paragraph claim either
 * package needs `firefly/security` and nothing else? Does a footprint it counts match the block it is
 * counting? And does it name a framework package that block does not list?
 *
 * @param  list<string>  $client  the `firefly/*` requires of packages/security-oauth2-client/composer.json
 * @param  list<string>  $server  the `firefly/*` requires of packages/security-oauth2-server/composer.json
 * @return array{quoting: int, counting: int, exclusivity: list<string>, enumeration: list<string>}
 */
function fireflyOAuth2InstallProse(array $client, array $server): array
{
    $quoting = 0;
    $counting = 0;
    $exclusivity = [];
    $enumeration = [];

    $sets = ['firefly/security-oauth2-client' => $client, 'firefly/security-oauth2-server' => $server];
    $required = array_unique([...$client, ...$server]);

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $index => $paragraph) {
            $lower = mb_strtolower($paragraph);

            // THE TRIGGER READS A WINDOW, NOT A PARAGRAPH, and the sentence that shipped is why. "both depend
            // only on `firefly/security`" names neither package: it introduces them, and the two `composer
            // require` lines that name them are the block underneath. A trigger that asked one paragraph to
            // hold both the claim and the package names walked straight past the only falsehood this canary
            // was written for. So the window is the paragraph plus the two that follow it, stopping at the
            // next heading — far enough to reach the listing a claim is introducing, near enough that the two
            // are one thought. The claim is still judged, and still quoted, as the paragraph it was written in.
            $window = '';
            foreach (array_slice($paragraphs, $index, 3) as $offset => $following) {
                if ($offset > 0 && str_starts_with(ltrim($following), '#')) {
                    break;
                }

                $window .= mb_strtolower($following)."\n\n";
            }

            // A page may name either package, or quote a `composer require` line, without owing anything here;
            // the sentence this guard exists for is the one that compares the two AND says what one costs.
            if (! str_contains($window, 'security-oauth2-client') || ! str_contains($window, 'security-oauth2-server')) {
                continue;
            }

            if (preg_match('/depend|requir|instal|arrastr|drag|pull/u', $lower) !== 1) {
                continue;
            }

            $quoting++;

            // (1) THE CLAIM THAT SHIPPED. "Neither package is a dependency of the other — both depend only on
            // `firefly/security`" was two thirds true, and the false third was the only dependency statement
            // the chapter made. The refusal is conditional on the derivation, not on a memory of it: while a
            // block really does name one firefly package there is nothing here to refuse, and the guard below
            // exercises that rather than describing it. The backtick matters — `firefly/security-oauth2-server`
            // contains the shorter name as a substring, and a paragraph saying "only the server" must not fire.
            if ((count($client) > 1 || count($server) > 1)
                && preg_match('/\b(only|solo|sólo|únicamente|unicamente)\b[^.]{0,80}?`firefly\/security`/u', $lower) === 1) {
                $exclusivity[] = $page.' says the OAuth2 packages need `firefly/security` and nothing else; the client\'s '
                    .'require block names '.count($client).' firefly packages and the server\'s '.count($server).': '
                    .mb_substr((string) preg_replace('/\s+/', ' ', trim($paragraph)), 0, 160);
            }

            // (2) A FOOTPRINT IS A COUNT, and a count is a claim. Every number a paragraph writes before
            // "framework packages" is read as one of the two blocks being counted, and it has to be one of the
            // two sizes those blocks really have.
            if (preg_match_all('/([\p{L}\d]+)\**\s+\**(?:framework packages|firefly packages|paquetes del framework|paquetes de firefly)\b/iu', $paragraph, $claims, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($claims as $claim) {
                $written = fireflyWrittenNumber($claim[1]);

                if ($written === null) {
                    continue;
                }

                $counting++;
                $counted = null;

                foreach ($sets as $package => $set) {
                    if (count($set) === $written) {
                        $counted = $package;

                        break;
                    }
                }

                if ($counted === null) {
                    $enumeration[] = $page.' counts an OAuth2 install footprint at '.$claim[1].' framework packages, and '
                        .'the require blocks name '.count($client).' (client) and '.count($server).' (server)';

                    continue;
                }

                // (3) And the paragraph owes the names, not just the number: a reader who is told the figure is
                // being told which packages arrive. Every entry of the block it counted has to appear in it,
                // short (`data`) or qualified (`firefly/data`) — whichever the sentence prefers.
                foreach ($sets[$counted] as $dependency) {
                    $short = substr($dependency, strlen('firefly/'));

                    if (str_contains($paragraph, '`'.$short.'`') || str_contains($paragraph, '`'.$dependency.'`')) {
                        continue;
                    }

                    $enumeration[] = $page.' counts '.$counted.'\'s footprint at '.$claim[1].' framework packages without '
                        .'naming `'.$dependency.'`, which its require block lists';
                }
            }

            // The mirror of (3): a paragraph counting require blocks may not qualify a framework package
            // NEITHER block names. The two subjects are excluded because naming them is the point — and
            // because neither require block lists the other, which is the claim beside this one.
            if (preg_match_all('/`(firefly\/[a-z0-9-]+)`/', $paragraph, $named) > 0) {
                foreach (array_unique($named[1]) as $package) {
                    if (in_array($package, $required, true) || array_key_exists($package, $sets)) {
                        continue;
                    }

                    $enumeration[] = $page.' counts the OAuth2 require blocks while naming `'.$package.'`, which neither '
                        .'block lists';
                }
            }
        }
    }

    return ['quoting' => $quoting, 'counting' => $counting, 'exclusivity' => $exclusivity, 'enumeration' => $enumeration];
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

    // docs/architecture.md's entry-point bullet, docs/modules/security.md's prose and its settings table,
    // book/src/10-security.md's own paragraph and its recap row, and — since the Spanish manuscript was
    // brought level, section for section — the same two in book/src-es/10-security.md. The canary counts
    // PARAGRAPHS, not pages, so a page that explains the rule in a second language raises it by however many
    // paragraphs it spends on it; what the count protects is the opposite case, a paragraph that stops
    // matching `prefersHtml` and silently stops being checked.
    expect($paragraphs)->toBe(7);
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

it('pins every DbHealthIndicator sentence to the matchIfMissing its own attribute declares', function () {
    // The eleventh guard, and the first to catch a stale DEFAULT rather than a stale count. `DbHealthIndicator`
    // shipped opt-in for one release and then became on-by-default, like Spring Boot's DataSourceHealthIndicator
    // auto-configuration. docs/modules/actuator.md and the CHANGELOG were corrected; five other places were not
    // — book/src/11-observability-actuator.md's explanation AND its recap row, book/src/94-glossary.md, and both
    // Spanish mirrors — all still describing the retired behaviour in the present tense. A reader who believed
    // them would leave a production database unchecked, because the book said the check was off until asked for.
    //
    // Nothing could see it. The listing beside the sentence had the real #[ConditionalOnProperty] line elided
    // out, so the verbatim guard was satisfied by a listing that no longer contained the evidence against the
    // paragraph it illustrated — which is the exact reason a listing guard is not a documentation guard.
    //
    // DERIVED: the attribute is read off the shipped class, and both rules fire only while matchIfMissing is
    // TRUE. The day the framework makes the indicator opt-in again they go quiet, which is correct — the
    // sentences they refuse become the true ones, and the opposite pair would be what needs writing.
    //
    // THE PRESENCE CHECK IS CONDITIONAL ON THE SAME DERIVATION, and that is not a detail. It shipped above
    // the guard clause instead of below it, so an opt-in indicator produced quoting = 0 and the canary went
    // red with a message blaming the book for deleting a paragraph that was still there — a guard whose whole
    // subject is a stale claim about a default, itself making one. The scan now lives in a function that
    // takes matchIfMissing as an argument, so the "they go quiet" promise is asserted rather than asserted-in-prose.
    $attributes = (new ReflectionClass(DbHealthIndicator::class))->getAttributes(ConditionalOnProperty::class);

    expect($attributes)->not->toBeEmpty('DbHealthIndicator no longer carries a #[ConditionalOnProperty] at all');

    $onByDefault = $attributes[0]->newInstance()->matchIfMissing;

    ['quoting' => $quoting, 'failures' => $failures] = fireflyDbHealthIndicatorProse($onByDefault);

    expect($failures)->toBe([]);

    if ($onByDefault) {
        expect($quoting)->toBeGreaterThan(0, 'no page quotes DbHealthIndicator\'s #[ConditionalOnProperty] any more, so this canary holds nothing');
    }

    // The promise, exercised rather than described: with the indicator opt-in, the same walk over the same
    // pages must refuse nothing and hold nothing to be missing. A presence assertion left outside this
    // branch is exactly the failure this line exists to catch.
    expect(fireflyDbHealthIndicatorProse(false))->toBe(['quoting' => 0, 'failures' => []]);
});

it('pins every page that explains the book\'s gate to what that gate now really does', function () {
    // The twelfth, and the one that guards a PROMISE rather than a fact. Wave R put book/src under the
    // provenance contract and, because a verbatim fragment of a real file cannot parse on its own, stopped
    // handing a `source:` listing to `php -l`. Five sentences across three pages went on describing the
    // retired arrangement: docs/contributing.md said the script lints "every fenced `php` listing", that this
    // "is the whole of the book's gate", that the manuscripts are "**not** under the provenance contract",
    // that "no chapter carries a `source:` … marker yet", that "DocsCodeAudit::AUDITED names no book path"
    // and that --require-provenance "is therefore part of no gate"; book/README.md and README.md repeated the
    // lint claim three more times. A gate that runs green while the page explaining it is false is worse than
    // no gate, because that page is the reason a contributor trusts the gate at all.
    //
    // EACH RETIRED SENTENCE IS PAIRED WITH THE FACT THAT RETIRED IT, and the fact is derived: the script's own
    // guard clause, the AUDITED list, and a walk of book/src counting how many of its `php` listings carry a
    // marker. A sentence is only refused while the thing it denies is true, so the day one of these decisions
    // is reversed the corresponding check goes quiet instead of forcing a lie in the other direction.
    //
    // A SECOND ROUND, for the same reason one wave later. Chapter 10A landed in book/src-es carrying sixteen
    // `source:` markers — the first Spanish page ever to have any — and four more sentences went false in the
    // opposite direction, each of them now DENYING a conversion that had already happened: README.md and
    // book/README.md called the Spanish manuscript `php -l`-verified "throughout", book/README.md said
    // `src-es/` "carries none yet", and docs/contributing.md said it "carries no markers". A reader trusting
    // those would believe sixteen listings were linted when the marker is exactly what stops them being.
    //
    // WHAT THIS CANNOT SEE, stated plainly: it knows the sentences that WERE wrong, not every sentence that
    // COULD be. A newly invented false claim about the gate is not in the list. What the pairing buys is that
    // these nine cannot come back, and that the three pages cannot quietly stop describing the gate at all —
    // which the presence check below is for. The structural half — that a page carrying markers must also be
    // in AUDITED, or neither the lint nor the comparison reads it — is asserted in tests/DocsCodeIsRealTest.php,
    // because it is a fact about the gate rather than about a sentence.
    $root = dirname(__DIR__);

    $script = (string) file_get_contents($root.'/book/build/verify_code.py');
    $exempts = preg_match('/if\s+lst\.origin\s+is\s+None:\s*\n\s*ok,\s*err\s*=\s*lint_php\(/', $script) === 1;

    // Read through reflection rather than as a constant expression: the value is the thing under test, and a
    // literal `in_array(..., DocsCodeAudit::AUDITED, true)` is folded to `true` at analysis time, which would
    // make the pairing below a constant rather than a derivation.
    $surface = (new ReflectionClassConstant(DocsCodeAudit::class, 'AUDITED'))->getValue();
    $audited = is_array($surface) && in_array('book/src', $surface, true);

    $audit = new DocsCodeAudit($root);
    $listings = 0;
    $marked = 0;

    foreach ($audit->markdownFiles() as $file) {
        if (! str_starts_with($file, 'book/src/')) {
            continue;
        }

        foreach ($audit->blocksIn($file) as $block) {
            if ($block->language !== 'php') {
                continue;
            }

            $listings++;

            if ($block->source !== null || $block->illustrative !== null) {
                $marked++;
            }
        }
    }

    $everyListingDeclaresItsOrigin = $listings > 0 && $marked === $listings;

    // Derived the same way, in the other tree: the day any Spanish page carries a marker, every sentence that
    // says the Spanish manuscript is linted end to end is false, because the marker is what ends the lint.
    $spanishConverted = false;
    foreach ((array) glob($root.'/book/src-es/*.md') as $path) {
        if (str_contains((string) file_get_contents((string) $path), '<!-- source:')) {
            $spanishConverted = true;

            break;
        }
    }

    /** @var list<array{0: string, 1: string, 2: bool}> $retired  page, the sentence fragment, the derived fact that makes it false */
    $retired = [
        ['docs/contributing.md', 'write every fenced', $exempts],
        ['docs/contributing.md', "That is\nthe whole of the book's gate", $audited],
        ['docs/contributing.md', 'are **not** under the provenance contract', $audited],
        ['docs/contributing.md', 'no chapter\ncarries a `source:`', $everyListingDeclaresItsOrigin],
        ['docs/contributing.md', 'names no book path', $audited],
        ['docs/contributing.md', 'is therefore part of no gate', $everyListingDeclaresItsOrigin],
        ['book/README.md', 'block in the manuscript is linted with the real PHP', $exempts],
        ['book/README.md', 'lints with `php -l`', $exempts],
        ['book/README.md', 'listing is `php -l`-clean (enforced by', $exempts],
        ['README.md', 'Every fenced PHP listing is `php -l`-verified', $exempts],
        ['README.md', '`php -l`-verified throughout.', $spanishConverted],
        ['book/README.md', 'carries none yet', $spanishConverted],
        ['book/README.md', "`php -l`-clean throughout\n(`verify_code.py`).", $spanishConverted],
        ['docs/contributing.md', 'is the surface still to convert. It carries no markers', $spanishConverted],
    ];

    $failures = [];

    foreach ($retired as [$page, $fragment, $isFalseNow]) {
        if ($isFalseNow && str_contains((string) file_get_contents($root.'/'.$page), $fragment)) {
            $failures[] = $page.' still says '.var_export($fragment, true);
        }
    }

    // And the three pages must still SAY something about the gate: a sentence deleted rather than corrected
    // would satisfy every check above and leave a contributor with nothing to read.
    $silent = array_values(array_filter(
        ['README.md', 'docs/contributing.md', 'book/README.md'],
        static fn (string $page): bool => ! str_contains((string) file_get_contents(dirname(__DIR__).'/'.$page), 'php -l'),
    ));

    expect($listings)->toBeGreaterThan(0, 'the walk found no php listing under book/src at all')
        ->and($failures)->toBe([])
        ->and($silent)->toBe([], 'a page that explains the book\'s gate no longer mentions `php -l`: '.implode(', ', $silent));
});

it('refuses any page that still ships CachedTransactionalConfiguration as application code', function () {
    // The thirteenth, and the first BILINGUAL one — written because an English correction and a Spanish page
    // disagreed inside a single release. `App\Support\CachedTransactionalConfiguration` was real for exactly one
    // version: a hand-written #[Configuration] whose only #[Bean] loaded the compiled TransactionalManifest,
    // because DataAutoConfiguration bound an unconditional empty one and #[Transactional] was otherwise a silent
    // no-op. The framework fixed that at the source, the skeleton deleted the file, docs/modules/transactional.md
    // and the CHANGELOG said so, and book/src/09-transactions.md was rewritten to say so — while
    // book/src-es/02-dependency-injection.md went on PRINTING the class as "un archivo real que el proyecto
    // firefly/skeleton distribuye de fábrica" and book/src-es/09-transactions.md repeated the claim twice more.
    //
    // A reader following the Spanish edition would create a class the framework now steps around, pinning their
    // application to a workaround for a bug it no longer has. Neither verify_code.py (which only lints) nor
    // DocsCodeIsRealTest (whose audited surface reaches one Spanish chapter, and which compares listings, never
    // sentences) can see a sentence, which is why it is here.
    //
    // DERIVED, twice over. The first fact is that the skeleton ships no such file — a walk, not a literal, so the
    // day someone re-adds it both rules go quiet by themselves. The second is that no class by that name exists
    // outside a test fixture, which is what makes PRINTING its body as shipped application code wrong.
    $root = dirname(__DIR__);

    $skeletonShipsIt = is_file($root.'/skeleton/app/Support/CachedTransactionalConfiguration.php');

    $outsideTests = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/packages', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file instanceof SplFileInfo && $file->getBasename() === 'CachedTransactionalConfiguration.php' && ! str_contains($file->getPathname(), '/tests/')) {
            $outsideTests[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    $isFixtureOnly = ! $skeletonShipsIt && $outsideTests === [];

    // A sentence that names the class beside the skeleton has to be RETIRING it, not shipping it. These are the
    // two editions' ways of saying "gone", plus a pointer at the test fixtures that legitimately still carry one.
    $retirementMarkers = ['deleted', 'drops', 'ships no', 'no longer', 'no distribuye', 'ya no', 'tests/'];

    $named = 0;
    $failures = [];

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (! str_contains($paragraph, 'CachedTransactionalConfiguration')) {
                continue;
            }

            $named++;
            $where = $page.': '.mb_substr((string) preg_replace('/\s+/', ' ', trim($paragraph)), 0, 140);

            // Printing the class body is the strongest form of the claim: a listing is read as code to copy.
            if ($isFixtureOnly && preg_match('/(?:final\s+)?class\s+CachedTransactionalConfiguration\b/', $paragraph) === 1) {
                $failures[] = $where;

                continue;
            }

            $mentionsTheSkeleton = str_contains($paragraph, 'skeleton') || str_contains($paragraph, 'andamiaje');

            if (! $skeletonShipsIt && $mentionsTheSkeleton) {
                $retires = false;
                foreach ($retirementMarkers as $marker) {
                    if (str_contains($paragraph, $marker)) {
                        $retires = true;

                        break;
                    }
                }

                if (! $retires) {
                    $failures[] = $where;
                }
            }
        }
    }

    expect($failures)->toBe([]);

    if ($isFixtureOnly) {
        expect($named)->toBeGreaterThan(0, 'no page mentions CachedTransactionalConfiguration any more, so this canary holds nothing');
    }
});

it('pins every anonymous-withdraw paragraph to the status samples/lumen really asserts', function () {
    // The fourteenth. `samples/lumen/tests/Web/WalletRestTest.php` is the book's worked security example, and it
    // answers an anonymous withdraw with 401/AUTHENTICATION_FAILED — a deliberate distinction the evaluator makes
    // (401 means signing in would help; 403 means it would not). book/src-es/04-first-http-api.md rendered that
    // same refusal as 403/ACCESS_DENIED, and book/src-es/10-security.md printed a listing ATTRIBUTED to this very
    // file asserting 403 and COMMAND_PROCESSING_ERROR — neither of which the file has ever contained since the
    // pair was split. Spanish has no `source:` markers, so the verbatim guard never compared them.
    //
    // DERIVED from the test's own body, so the day the sample changes its mind the book is told which pages have
    // to change with it. The trigger is narrow on purpose: a paragraph is only judged when it is about a withdraw
    // AND about an unauthenticated caller AND already names a 403 — a page is free to explain the 403 half on its
    // own without owing the 401.
    $sample = (string) file_get_contents(dirname(__DIR__).'/samples/lumen/tests/Web/WalletRestTest.php');

    $blocks = preg_split('/\n(?=it\()/', $sample);
    $anonymous = null;
    foreach ($blocks === false ? [] : $blocks as $block) {
        if (str_contains($block, 'unauthenticated withdraw')) {
            $anonymous = $block;

            break;
        }
    }

    expect($anonymous)->not->toBeNull('samples/lumen no longer has a test for an unauthenticated withdraw');

    preg_match('/->assertStatus\((\d{3})\)/', (string) $anonymous, $status);
    preg_match("/->assertJsonPath\('code', '([A-Z_]+)'\)/", (string) $anonymous, $code);

    $expected = $status[1] ?? '';
    $expectedCode = $code[1] ?? '';

    expect($expected)->not->toBe('', 'the unauthenticated-withdraw test asserts no status at all')
        ->and($expectedCode)->not->toBe('', 'the unauthenticated-withdraw test asserts no error code at all');

    $anonymousMarkers = [
        'unauthenticated', 'no authenticated principal', 'not signed in', 'anonymous',
        'principal autenticado', 'sin ningún principal', 'sin autenticar', 'anónimo',
    ];

    $judged = 0;
    $failures = [];

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            $aboutAWithdraw = stripos($paragraph, 'withdraw') !== false || stripos($paragraph, 'retiro') !== false;

            if (! $aboutAWithdraw) {
                continue;
            }

            $anonymousHere = false;
            foreach ($anonymousMarkers as $marker) {
                if (stripos($paragraph, $marker) !== false) {
                    $anonymousHere = true;

                    break;
                }
            }

            if (! $anonymousHere) {
                continue;
            }

            $judged++;

            // The status is what a reader acts on, and it is the one thing every such paragraph names — the
            // error code often lives in the JSON document a paragraph away. So the status is required, and a
            // code is only refused when the paragraph names the WRONG one.
            $namesAnotherCode = preg_match('/\b[A-Z][A-Z_]{4,}\b/', $paragraph, $named) === 1
                && $named[0] !== $expectedCode
                && str_contains($sample, "'".$named[0]."'");

            if (! str_contains($paragraph, $expected) || $namesAnotherCode) {
                $failures[] = $page.': '.mb_substr((string) preg_replace('/\s+/', ' ', trim($paragraph)), 0, 140);
            }
        }
    }

    expect($failures)->toBe([], sprintf(
        'a page describes an unauthenticated withdraw without the %s/%s the sample test asserts: %s',
        $expected,
        $expectedCode,
        implode(' | ', $failures),
    ))->and($judged)->toBeGreaterThan(0, 'no page describes an anonymous withdraw any more, so this canary holds nothing');
});

it('pins every masking enumeration to the regex the masker really carries', function () {
    // The fifteenth, and the one whose staleness is a SECURITY claim. `SensitiveValueMasker` grew two words after
    // an audit — `authorization` and `headers` — because firefly.observability.tracing.otlp.headers documents
    // `authorization=Bearer …` as its contents and none of the six original words appear in `headers`, so /env
    // rendered a vendor credential verbatim. The same commit inverted the ordering so the KEY decides before the
    // value's type, closing an array-valued bypass that rendered a whole JWT keyring in full.
    //
    // book/src/11-observability-actuator.md was rewritten around the shipped class. book/src-es was not: it went
    // on printing the PRE-FIX EnvEndpoint — six words, is_array() first — as shipped framework code, and its recap
    // row enumerated six. A Spanish reader was shown a masker that leaks a keyring and told their tracing headers
    // were not masked. Nothing caught it; no canary pinned the word list.
    //
    // DERIVED from both maskers the framework ships, by reflection over the private constant, and the subject of
    // each enumeration is read from the paragraph rather than assumed — docs/modules/observability.md is about
    // HeaderMasker, which has its own list. A run may name fewer words than its list only when the paragraph names
    // the rest as inline code beside it, which is how that page legitimately writes `authorization` and `cookie`
    // out in front of the pattern.
    $envWords = fireflyMaskingWords(SensitiveValueMasker::class);
    $headerWords = fireflyMaskingWords(HeaderMasker::class);

    expect($envWords)->not->toBeEmpty('SensitiveValueMasker::SENSITIVE parsed to no words at all')
        ->and($headerWords)->not->toBeEmpty('HeaderMasker::SENSITIVE parsed to no words at all');

    $runs = 0;
    $failures = [];

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            // A markdown table escapes the alternation's pipes; a code fence does not. Both are the same claim.
            if (preg_match_all('/password((?:\\\\?\|[a-z]+)+)/', $paragraph, $matches) === 0) {
                continue;
            }

            $target = str_contains($paragraph, 'HeaderMasker') ? $headerWords : $envWords;
            $where = $page.': '.mb_substr((string) preg_replace('/\s+/', ' ', trim($paragraph)), 0, 140);

            foreach ($matches[1] as $tail) {
                $runs++;
                $named = explode('|', 'password'.str_replace('\\', '', $tail));
                sort($named);

                $invented = array_values(array_diff($named, $target));
                $missing = array_values(array_filter(
                    array_diff($target, $named),
                    static fn (string $word): bool => ! str_contains($paragraph, '`'.$word.'`'),
                ));

                if ($invented !== [] || $missing !== []) {
                    $failures[] = $where.' [missing: '.implode(',', $missing).'] [invented: '.implode(',', $invented).']';
                }
            }
        }
    }

    expect($failures)->toBe([])
        ->and($runs)->toBeGreaterThan(0, 'no page enumerates a masking pattern any more, so this canary holds nothing');
});

it('pins every EloquentRepository helper sentence to the visibility and the coverage the class really has', function () {
    // The sixteenth. Chapter 5 said "Three private helpers carry everything those two bodies do not spell out, and
    // every read in the class goes through the same three." Three errors in one sentence: reading() and
    // translating() are PROTECTED — the deliberate seam a subclass repository overrides, which is the very thing
    // this chapter's "annotate an override that does nothing but return parent::findAll();" recipe rests on, so
    // calling them private tells a reader the recipe is out of reach; the two listed bodies use FOUR helpers, with
    // narrow() called in the excerpt directly above the sentence; and existsById()/count()/existsByExample()/
    // countByExample() go straight to query() and never touch reading() at all.
    //
    // DERIVED: the visibilities come from reflection, the helper count from the two method bodies the chapter
    // actually prints, and the bypassing reads from a walk of the class's own source. Every rule below is
    // conditional on the fact it protects, so a framework that makes reading() private again — or routes count()
    // through it — silences the rule that would then be wrong instead of forcing a lie into the book.
    $file = (new ReflectionClass(EloquentRepository::class))->getFileName();

    expect($file)->toBeString('EloquentRepository has no source file');

    $source = (string) file_get_contents((string) $file);

    $visibility = static function (string $method): string {
        $reflected = new ReflectionMethod(EloquentRepository::class, $method);

        return $reflected->isPrivate() ? 'private' : ($reflected->isProtected() ? 'protected' : 'public');
    };

    $seams = ['reading', 'translating'];
    $seamsAreProtected = true;
    foreach ($seams as $seam) {
        $seamsAreProtected = $seamsAreProtected && $visibility($seam) === 'protected';
    }

    // The two bodies the chapter prints side by side, and every $this->helper() they reach for.
    $helpers = [];
    foreach (['findBySpecification', 'findBySpecificationPaged'] as $method) {
        preg_match('/\n    public function '.$method.'\(.*?\n    \}/s', $source, $body);

        expect($body[0] ?? '')->not->toBe('', 'EloquentRepository no longer declares '.$method.'()');

        preg_match_all('/\$this->(\w+)\(/', $body[0] ?? '', $calls);
        $helpers = array_merge($helpers, $calls[1]);
    }
    $helperCount = count(array_unique($helpers));

    // Reads that answer a question ABOUT rows: they open no builder through reading(), on purpose. A read that
    // merely delegates to another read (getById() -> findById()) is not one of them — it reaches reading() by
    // proxy — so a body that calls a sibling finder is excluded rather than counted as a bypass.
    preg_match_all('/\n    public function (\w+)\(.*?\n    \}/s', $source, $methods, PREG_SET_ORDER);
    $bypassing = [];
    foreach ($methods as [$whole, $name]) {
        $isARead = preg_match('/^(?:find|exists|count|get)/', $name) === 1;
        $delegates = preg_match('/\$this->(?:find|get)\w*\(/', $whole) === 1;

        if ($isARead && ! $delegates && ! str_contains($whole, 'reading(')) {
            $bypassing[] = $name;
        }
    }

    $judged = 0;
    $failures = [];

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            $aboutTheHelpers = str_contains($paragraph, 'reading(') || str_contains($paragraph, 'translating(');

            if (! $aboutTheHelpers) {
                continue;
            }

            $judged++;
            $where = $page.': '.mb_substr((string) preg_replace('/\s+/', ' ', trim($paragraph)), 0, 140);

            // Calling the two seams private, with no mention of the protected half, is the claim that shipped.
            $saysPrivate = str_contains($paragraph, 'private') || str_contains($paragraph, 'privad');
            $saysProtected = str_contains($paragraph, 'protected') || str_contains($paragraph, 'protegid');

            if ($seamsAreProtected && $saysPrivate && ! $saysProtected) {
                $failures[] = $where.' [calls the protected seam private]';
            }

            // "Three helpers" / "Tres ayudantes": a count of a set this test has already derived.
            if (preg_match('/(\p{L}+)\s+(?:private\s+|protected\s+|privados\s+|protegidos\s+)?(?:helpers?|ayudantes|auxiliares)\b/u', $paragraph, $counted) === 1) {
                $written = fireflyWrittenNumber($counted[1]);

                if ($written !== null && $written !== $helperCount) {
                    $failures[] = $where.' [counts '.$written.' helpers, the two bodies use '.$helperCount.']';
                }
            }

            // And an exhaustive claim has to carry the qualifier that makes it true, because some reads do not.
            $claimsEveryRead = str_contains($paragraph, 'and the rest')
                || str_contains($paragraph, 'every read in the class')
                || str_contains($paragraph, 'y el resto')
                || str_contains($paragraph, 'toda lectura de la clase');
            $qualified = str_contains($paragraph, 'opens a builder') || str_contains($paragraph, 'abre un builder');

            if ($bypassing !== [] && $claimsEveryRead && ! $qualified) {
                $failures[] = $where.' [claims every read, but '.implode(', ', $bypassing).' bypass reading()]';
            }
        }
    }

    expect($failures)->toBe([])
        ->and($judged)->toBeGreaterThan(0, 'no page explains EloquentRepository\'s read helpers any more, so this canary holds nothing');
});

it('pins every recording-doubles count to the doubles packages/testing really ships', function () {
    // The seventeenth. Chapter 12 promised "the ten recording doubles" beside a table of ten rows while
    // packages/testing/src/Double/ shipped eleven: RecordingAuthenticationEvents, added by the security wave,
    // was missing from both. That was not a cosmetic omission — six new sections of Chapter 10 call
    // `$this->events->interactive()`, `->logouts()` and `->denials()` throughout, and with the class unnamed
    // in either chapter a reader could not reproduce one of those flow tests. The Spanish edition carried the
    // same "diez" and the same ten rows, which is the second reason this walks both.
    //
    // DERIVED: the directory is read, and a class another double INSTANTIATES is that double's output rather
    // than a double of its own — RecordingTracer hands out RecordedSpan, which is why the directory holds
    // twelve files and the catalogue eleven entries. The instantiation is looked for in code with comments
    // stripped, because RecordingAuthenticationEvents' docblock writes `new RecordingAuthenticationEvents(…)`
    // and a bare grep would read that as a sibling being produced.
    $directory = dirname(__DIR__).'/packages/testing/src/Double';
    $sources = [];

    foreach ((array) glob($directory.'/*.php') as $path) {
        $sources[basename((string) $path, '.php')] = (string) file_get_contents((string) $path);
    }

    expect($sources)->not->toBe([], 'packages/testing/src/Double is empty, so this canary holds nothing');

    // Comments stripped through the tokenizer: a class named only in a docblock is not instantiated.
    $code = [];
    foreach ($sources as $name => $source) {
        $kept = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $kept .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

                continue;
            }
            $kept .= $token;
        }
        $code[$name] = $kept;
    }

    $doubles = [];
    foreach (array_keys($sources) as $name) {
        $produced = false;
        foreach ($code as $owner => $body) {
            if ($owner !== $name && preg_match('/\bnew\s+'.preg_quote($name, '/').'\s*\(/', $body) === 1) {
                $produced = true;

                break;
            }
        }

        if (! $produced) {
            $doubles[] = $name;
        }
    }
    sort($doubles);

    $judged = 0;
    $failures = [];

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            // "the ten recording doubles", "| 10 recording doubles |", "los diez dobles de grabación".
            if (preg_match('/([\p{L}\d]+)\s+(?:recording doubles|dobles de grabación)/u', $paragraph, $counted) !== 1) {
                continue;
            }

            $written = fireflyWrittenNumber($counted[1]);

            if ($written === null) {
                continue;
            }

            $judged++;

            if ($written !== count($doubles)) {
                $failures[] = $page.': counts '.$written.' recording doubles, packages/testing ships '
                    .count($doubles).' ('.implode(', ', $doubles).')';
            }
        }
    }

    // And the English chapter — the edition docs/contributing.md names as the reference — must NAME every one
    // of them. A double the manuscript never spells cannot be reached by a reader who only has the book, and
    // that is precisely how RecordingAuthenticationEvents went missing while its methods were being used three
    // sections at a time. The Spanish chapter is deliberately outside this half: book/README.md records that
    // it trails the English one, and a rule that forced a name in before the section explaining it arrived
    // would buy its strictness with a page nobody could read.
    $chapter = (string) file_get_contents(dirname(__DIR__).'/book/src/12-testing.md');
    $unnamed = array_values(array_filter($doubles, static fn (string $name): bool => ! str_contains($chapter, $name)));

    expect($failures)->toBe([])
        ->and($unnamed)->toBe([], 'book/src/12-testing.md names no '.implode(', ', $unnamed))
        ->and($judged)->toBeGreaterThan(0, 'no page counts the recording doubles any more, so this canary holds nothing');
});

it('pins every derived-method count to the @method tags RecordRepository really carries', function () {
    // The eighteenth, and the one the wave's own diff created. Chapter 5 prints RecordRepository's docblock
    // verbatim and then says "Not one of those five methods is declared anywhere in the class" — true until
    // the same commit added a sixth `@method` tag to the listing above it, at which point the page showed six
    // derived methods and counted five, two lines apart. Nothing could see it: DocsCodeAudit compares the
    // listing against the file and never reads the prose, and the sentence names no symbol any existing
    // trigger watches.
    //
    // DERIVED: the tags are counted in the fixture, and the "declared nowhere" half is checked too — a class
    // that started declaring one of its derived methods would make the sentence false in the other direction,
    // and the count alone would not notice.
    $path = dirname(__DIR__).'/packages/data/tests/Fixtures/Repository/RecordRepository.php';
    $source = (string) file_get_contents($path);

    expect($source)->not->toBe('', 'the RecordRepository fixture is gone, so this canary holds nothing');

    preg_match_all('/^\s*\*\s*@method\s+\S+\s+(\w+)\(/m', $source, $tags);
    $derived = array_values(array_unique($tags[1]));
    $count = count($derived);

    // A tag whose method the class also declares is not a derived method at all.
    $declared = array_values(array_filter(
        $derived,
        static fn (string $method): bool => preg_match('/\n    (?:public|protected|private)\s+function\s+'.preg_quote($method, '/').'\s*\(/', $source) === 1,
    ));

    $judged = 0;
    $failures = [];

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $index => $paragraph) {
            // Only a sentence sitting beside a RecordRepository listing owes this count: the paragraph two
            // above it is the one that carries the `source:` marker and the fence.
            $near = implode("\n", array_slice($paragraphs, max($index - 2, 0), 3));

            if (! str_contains($near, 'RecordRepository')) {
                continue;
            }

            if (preg_match('/(?:those|esos|esas)\s+([\p{L}\d]+)\s+(?:methods|métodos)/u', $paragraph, $counted) !== 1) {
                continue;
            }

            $written = fireflyWrittenNumber($counted[1]);

            if ($written === null) {
                continue;
            }

            $judged++;

            if ($written !== $count) {
                $failures[] = $page.': counts '.$written.' derived methods, RecordRepository carries '
                    .$count.' @method tags ('.implode(', ', $derived).')';
            }

            if (str_contains($paragraph, 'declared anywhere') && $declared !== []) {
                $failures[] = $page.': says none of them is declared, but the class declares '
                    .implode(', ', $declared);
            }
        }
    }

    expect($failures)->toBe([])
        ->and($judged)->toBeGreaterThan(0, 'no page counts RecordRepository\'s derived methods any more, so this canary holds nothing');
});

it('pins every "complete in both languages" claim to how far apart the two manuscripts really are', function () {
    // The nineteenth, and the one that guards a claim a reader acts on by CHOOSING AN EDITION. Until wave R
    // the two manuscripts were line-for-line the same size, and README.md ("**complete and bilingual (English
    // + Spanish)**") and book/README.md ("The manuscript is **complete** in both languages") were simply
    // true. Then five chapters grew in English alone — 05 went 565 -> 1022 lines against 565 in Spanish, 10
    // went 565 -> 1093 against 578, 12 went 518 -> 701 against 518 — and both sentences went on telling a
    // reader who picks up the Spanish PDF that it is the same book. docs/contributing.md recorded the lag,
    // but only for the provenance markers, never for content.
    //
    // DERIVED, AND SYMMETRICAL. The gap is measured, never typed: every chapter that exists in both trees is
    // compared, and a pair counts as diverged when the Spanish file is shorter than THRESHOLD of the English
    // one. While anything diverges, a completeness claim on either page must carry the caveat; when nothing
    // does any more, the caveat must GO — a warning that outlives its reason teaches a reader to ignore
    // warnings. And every chapter the caveat names by number has to be one of the diverged ones, so the list
    // cannot quietly go stale in either direction.
    $threshold = 0.9;

    $root = dirname(__DIR__);
    $behind = [];

    foreach ((array) glob($root.'/book/src/*.md') as $path) {
        $name = basename((string) $path);
        $spanish = $root.'/book/src-es/'.$name;

        if (! is_file($spanish)) {
            continue;
        }

        $english = count(file((string) $path) ?: []);
        $translated = count(file($spanish) ?: []);

        if ($english > 0 && $translated < $english * $threshold) {
            $behind[$name] = $translated.'/'.$english;
        }
    }

    // "05-persistence-repositories.md" -> 5, so a caveat writing "chapters 5, 9, 10" can be checked.
    $numbers = [];
    foreach (array_keys($behind) as $name) {
        if (preg_match('/^(\d+)/', $name, $matched) === 1) {
            $numbers[] = (int) $matched[1];
        }
    }

    $failures = [];
    $judged = 0;

    foreach (['README.md', 'book/README.md'] as $page) {
        // THE CAVEAT IS READ WHERE THE CLAIM IS MADE, not anywhere on the page. README.md writes "behind" in
        // four unrelated sentences (a bean wired in behind its port, a dashboard behind a flag) and "Spanish"
        // in a container fixture's name, so a page-wide grep for both answers true with no caveat written at
        // all — which is exactly the false green this canary would then hand back. The window is the claiming
        // paragraph and the few that follow it, stopping at the next heading: far enough to let book/README.md
        // put the caveat in its own paragraph under the same `## Manuscript status`, near enough that a reader
        // who reads the claim reads the caveat too.
        //
        // The paragraphs are split here rather than taken from fireflyProsePages(), because that walk covers
        // README.md, docs/ and both manuscripts — and NOT book/README.md, which is one of the two pages this
        // canary exists for. Reading it through the walk left the book's own page silently unjudged, which the
        // $judged canary below now makes impossible to repeat.
        $split = preg_split('/\n\s*\n/', (string) file_get_contents($root.'/'.$page));
        $paragraphs = $split === false ? [] : $split;
        $claim = null;

        foreach ($paragraphs as $index => $paragraph) {
            if (preg_match('/complete\b[^.]{0,80}\bboth languages|complete and bilingual/i', $paragraph) === 1) {
                $claim = $index;

                break;
            }
        }

        $window = '';
        if ($claim !== null) {
            foreach (array_slice($paragraphs, $claim, 5) as $offset => $paragraph) {
                if ($offset > 0 && str_starts_with(ltrim($paragraph), '#')) {
                    break;
                }

                $window .= $paragraph."\n\n";
            }
        }

        $claimsParity = $claim !== null;
        $caveat = preg_match('/(trails?|behind|has not received)\b/i', $window) === 1
            && preg_match('/\bSpanish\b/i', $window) === 1;

        if ($claimsParity) {
            $judged++;
        }

        if ($behind !== [] && $claimsParity && ! $caveat) {
            $failures[] = $page.' claims the book is complete in both languages while the Spanish edition is '
                .'behind on '.implode(', ', array_map(
                    static fn (string $name, string $sizes): string => $name.' ('.$sizes.')',
                    array_keys($behind),
                    array_values($behind),
                ));
        }

        if ($behind === [] && $caveat) {
            $failures[] = $page.' still warns that the Spanish edition trails the English one, and no chapter '
                .'pair diverges any more — delete the warning rather than leaving it to be ignored';
        }

        // Any chapter the caveat names by number must really be one of the diverged ones.
        if ($caveat && preg_match('/chapters?\s+([\d,\s]*\d)(?:\s+and\s+(\d+))?/i', $window, $listed) === 1) {
            $named = array_map('intval', preg_split('/[,\s]+/', trim($listed[1])) ?: []);
            if (isset($listed[2])) {
                $named[] = (int) $listed[2];
            }

            $wrong = array_values(array_diff(array_filter($named), $numbers));

            if ($wrong !== []) {
                $failures[] = $page.' names chapter(s) '.implode(', ', $wrong).' as behind, and they are not: '
                    .'the diverged chapters are '.implode(', ', $numbers);
            }
        }
    }

    expect($failures)->toBe([])
        ->and($judged)->toBe(2, 'one of README.md / book/README.md no longer says how complete the book is in each language, so this canary is only half holding');
});

it('pins every chapter count to the chapters the manuscript really has', function () {
    // The twentieth, and the one the wave's own diff created THREE TIMES IN ONE FILE. Chapter 10A was slotted
    // in after the security chapter, taking the book from fourteen chapters to fifteen. The task that added it
    // corrected the one sentence the plan had noticed — README.md's "**fifteen chapters**" — and left two more
    // counts on the same page spelled as a numeral: "the complete bilingual book (14 chapters + appendices)"
    // in the documentation index, and "(14 chapters + appendices, EN + ES, PDF + EPUB)" in the roadmap. The
    // first file anyone reads then said fifteen in one paragraph and fourteen in two others.
    //
    // NOTHING COULD SEE IT. DocsCodeIsRealTest audits fenced blocks, and README.md is in its surface, so the
    // page was green while contradicting itself in prose — a count is a claim, and the cheapest one to get
    // wrong, which is the premise this whole file was written on.
    //
    // DERIVED FROM THE MANUSCRIPT, in words and in digits, in English and in Spanish. A chapter is a file in
    // book/src whose name opens with a number between 01 and 89 — 00 is the quick start, 90 and 94 are the
    // appendices — so 04a and 10a count themselves and the next slotted-in chapter needs no edit here. Every
    // page that writes a number immediately before "chapters" or "capítulos" is held against it; a token that
    // is not a number ("Later chapters", "los Capítulos 7 y 8") reads as no claim at all and is skipped, which
    // is why the canary below insists the walk still found some.
    $root = dirname(__DIR__);

    $chapters = static function (string $tree) use ($root): array {
        $found = [];

        foreach ((array) glob($root.'/'.$tree.'/*.md') as $path) {
            if (preg_match('/^(\d{2})[a-z]?-/', basename((string) $path), $matched) === 1) {
                $number = (int) $matched[1];

                if ($number >= 1 && $number <= 89) {
                    $found[] = basename((string) $path);
                }
            }
        }

        sort($found);

        return $found;
    };

    $english = $chapters('book/src');
    $spanish = $chapters('book/src-es');
    $count = count($english);

    $pages = fireflyProsePages();
    // book/README.md is outside fireflyProsePages() — that walk covers README.md, docs/ and the two
    // manuscripts — and it carries two of the six counts, so it is read here explicitly. The nineteenth canary
    // learnt the same lesson about the same file.
    $split = preg_split('/\n\s*\n/', (string) file_get_contents($root.'/book/README.md'));
    $pages['book/README.md'] = $split === false ? [] : $split;

    $failures = [];
    $judged = 0;

    foreach ($pages as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (preg_match_all('/([\p{L}\d]+)\**\s+\**(chapters|cap[ií]tulos)\b/iu', $paragraph, $claims, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($claims as $claim) {
                $written = fireflyWrittenNumber($claim[1]);

                if ($written === null) {
                    continue;
                }

                $judged++;

                if ($written !== $count) {
                    $failures[] = $page.' counts the book at '.$claim[1].' '.$claim[2].', and book/src holds '
                        .$count.': '.implode(', ', $english);
                }
            }
        }
    }

    expect($failures)->toBe([])
        // A count claimed of "EN + ES" is only true while both trees hold the same chapters, so the day one
        // edition gains a chapter alone, the sentences above become half-true rather than false — which is the
        // shape of drift this file exists to refuse.
        ->and($spanish)->toBe($english, 'the two manuscripts no longer hold the same chapters, so every "N chapters, EN + ES" sentence is only true of one edition')
        ->and($count)->toBeGreaterThan(0, 'book/src holds no numbered chapter at all, so this canary holds nothing')
        ->and($judged)->toBeGreaterThan(0, 'no page counts the book\'s chapters any more, so this canary holds nothing');
});

it('pins every OAuth2 preset paragraph to the client-authentication methods the presets really carry', function () {
    // The twenty-first, and the second to catch a stale DEFAULT rather than a stale count — this one a default
    // that never existed. Chapter 10A shipped, in both editions, with "five names are **presets** … whose
    // endpoints, default scopes, `client_name` and client-authentication method are built in", and
    // docs/modules/security-oauth2-client.md had been saying the same thing since the module was written.
    // CommonOAuth2Provider says the opposite, in capitals, in its own class docblock: only Google and GitHub
    // name a method, because neither provider issues a web client without a secret, and the three per-tenant
    // presets leave it to the registration precisely because Okta, Keycloak and Entra host public clients as a
    // matter of course. A reader configuring a secret-less Keycloak registration was being promised
    // `client_secret_basic` by three documents and handed `none` — a public client — by the mapper.
    //
    // NOTHING COULD SEE IT. The listing above the sentence is audited byte for byte and was innocent: the
    // falsehood lives in the paragraph beside it, in two languages, worded identically. A green gate with a
    // false sentence in it is a failed wave.
    //
    // DERIVED TWICE OVER, and neither half is typed out here. Which presets settle the method is read off
    // CommonOAuth2Provider::registration(); what the others land on instead is read off the MAPPER, by running
    // it — a per-tenant registration with a secret and the same one without, through the real
    // OAuth2ClientPropertiesMapper, with every endpoint spelled out so no discovery request is made. The day
    // Keycloak's preset gains a method, or the deduction changes, the derived values change and the sentences
    // that quote them go red.
    $builtIn = [];
    $deduced = [];

    foreach (CommonOAuth2Provider::cases() as $preset) {
        $named = $preset->registration()['client_authentication_method'] ?? null;

        if (is_string($named)) {
            $builtIn[] = $preset->value;

            continue;
        }

        $deduced[] = $preset->value;
    }

    expect($builtIn)->not->toBeEmpty('no OAuth2 preset names a client-authentication method any more, so this canary holds nothing');

    /**
     * The method a per-tenant registration really ends up presenting, read out of the mapper rather than
     * restated: the plan's own three-step resolution (configured, else the preset's, else deduced from the
     * secret) is the sentence under test, so nothing here may anticipate its answer.
     *
     * @param  array<string, mixed>  $registration
     */
    $method = static function (string $preset, array $registration): string {
        $issuer = 'https://sso.example.test/realms/corp';
        $config = new Config(new ConfigRepository(['firefly' => ['security' => ['oauth2' => ['client' => [
            'registration' => [$preset => $registration],
            // Every endpoint spelled out: with nothing left to discover, resolve() never reaches OidcDiscovery,
            // so this derivation is as request-free as the boot validation it is reading the answer out of.
            'provider' => [$preset => [
                'issuer_uri' => $issuer,
                'authorization_uri' => $issuer.'/protocol/openid-connect/auth',
                'token_uri' => $issuer.'/protocol/openid-connect/token',
                'jwk_set_uri' => $issuer.'/protocol/openid-connect/certs',
                'user_info_uri' => $issuer.'/protocol/openid-connect/userinfo',
            ]],
        ]]]]]));
        $settings = new OAuth2ClientSettings;

        $mapper = new OAuth2ClientPropertiesMapper(
            OAuth2ClientProperties::fromConfig($config),
            new OidcDiscovery(new Container, new CacheRepository(new ArrayStore), $settings),
            $settings,
        );

        return $mapper->registration($preset)->clientAuthenticationMethod->value;
    };

    $tenant = $deduced[0] ?? throw new RuntimeException('every OAuth2 preset now settles the method, so there is no deduction left to derive');
    $withSecret = $method($tenant, ['client_id' => 'portal', 'client_secret' => 'kc-secret']);
    $withoutSecret = $method($tenant, ['client_id' => 'portal']);

    // The fact the documents were getting wrong, asserted before it is used to judge them: a per-tenant preset
    // does not settle the method, and the two answers a reader can actually get are different from each other.
    expect($withSecret)->not->toBe($withoutSecret);

    $outcomes = [$withSecret, $withoutSecret];

    ['quoting' => $quoting, 'failures' => $failures] = fireflyOAuth2PresetProse($builtIn, $deduced, $outcomes);

    expect($failures)->toBe([])
        ->and($quoting)->toBeGreaterThan(0, 'no page presents the OAuth2 presets any more, so this canary holds nothing')
        // The promise, exercised rather than described: with every preset settling the method there is nothing
        // to qualify, and the same walk over the same pages must refuse nothing and hold nothing.
        ->and(fireflyOAuth2PresetProse([...$builtIn, ...$deduced], [], $outcomes))->toBe(['quoting' => 0, 'failures' => []]);
});

it('pins every OAuth2 install-footprint sentence to the two composer.json require blocks', function () {
    // The twenty-second, and the one a green gate could never have caught. Chapter 10A introduced its two
    // packages with "Neither package is a dependency of the other — both depend only on `firefly/security` —
    // and installing one never drags the other in", in English and, word for word, in Spanish. The first and
    // third clauses are true. The middle one was the chapter's ONLY dependency statement, and it was false:
    // packages/security-oauth2-client/composer.json requires seven firefly packages and
    // packages/security-oauth2-server/composer.json eleven. A reader taking the sentence at face value would
    // run `composer require firefly/security-oauth2-server` expecting one framework package and receive
    // eleven, among them firefly/data and firefly/scheduling — a Postgres-shaped surprise in a deployment that
    // had installed an identity provider.
    //
    // NOTHING COULD SEE IT. The two `composer require` lines under the sentence are audited as a listing, and
    // a listing audit compares shape, not consequence: both lines were correct and the paragraph above them
    // was not. No canary in this file had ever read a composer.json require block.
    //
    // DERIVED FROM THE BLOCKS THEMSELVES. The two sets are read out of the manifests at test time — every
    // `firefly/*` key of each `require` — so the day the server drops firefly/resilience or the client gains a
    // package, the counts and the names the chapter owes change with them and the sentences that quote them go
    // red. The install-footprint claim is stated here rather than merely permitted, because the wave's rule is
    // that a claim with no file behind it does not ship: this test is that file.
    $requires = static function (string $package): array {
        /** @var array{require?: array<string, string>} $manifest */
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/packages/'.$package.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $firefly = array_values(array_filter(
            array_keys($manifest['require'] ?? []),
            static fn (string $name): bool => str_starts_with($name, 'firefly/'),
        ));
        sort($firefly);

        return $firefly;
    };

    $client = $requires('security-oauth2-client');
    $server = $requires('security-oauth2-server');

    // The three facts the corrected paragraph rests on, asserted before they are used to judge anything.
    // Neither block names the other package — that is what "neither names the other" means, and it is the
    // clause the old sentence got right. `firefly/security` is in both, and it is the only package of the
    // security family in either, which is the accurate claim that replaced the false one.
    expect($client)->not->toContain('firefly/security-oauth2-server')
        ->and($server)->not->toContain('firefly/security-oauth2-client')
        ->and($client)->toContain('firefly/security')
        ->and($server)->toContain('firefly/security');

    foreach (['client' => $client, 'server' => $server] as $role => $set) {
        $security = array_values(array_filter(
            $set,
            static fn (string $name): bool => str_starts_with($name, 'firefly/security'),
        ));

        expect($security)->toBe(['firefly/security'], 'the OAuth2 '.$role.' now requires a second security package, so '
            .'"the only security package either one names" is no longer true of it');
    }

    // Counting is how the guard tells the two footprints apart, so equal sizes would make it ambiguous.
    expect(count($client))->not->toBe(count($server));

    [
        'quoting' => $quoting,
        'counting' => $counting,
        'exclusivity' => $exclusivity,
        'enumeration' => $enumeration,
    ] = fireflyOAuth2InstallProse($client, $server);

    expect($exclusivity)->toBe([])
        ->and($enumeration)->toBe([])
        ->and($quoting)->toBeGreaterThan(0, 'no page compares the two OAuth2 packages any more, so this canary holds nothing')
        ->and($counting)->toBeGreaterThan(0, 'no page states either OAuth2 install footprint any more, so this canary holds nothing')
        // The promise, exercised rather than described: were each package to require `firefly/security` alone,
        // "both depend only on `firefly/security`" would be true as written, and the same walk over the same
        // pages must refuse no sentence for saying it.
        ->and(fireflyOAuth2InstallProse(['firefly/security'], ['firefly/security'])['exclusivity'])->toBe([]);
});

/**
 * Every fenced block of a Markdown page, in order, with the provenance comment that introduces it.
 *
 * `tests/DocsCodeIsRealTest.php` already reads these blocks to compare a listing against the file it names.
 * What it cannot see is the OTHER edition: the two manuscripts are separate trees, each green on its own,
 * and nothing had ever put a Spanish block beside the English one it translates. That is the gap this
 * parser exists to close, so it is deliberately the simplest thing that can pair two trees — blocks in
 * document order, the marker line that precedes a fence carried along with it, and the fence's info string
 * kept because `php` is half of what the claim below is scoped to.
 *
 * A blank line between the marker and the fence is tolerated; any other non-empty line drops the marker,
 * because a `<!-- source: … -->` comment two paragraphs up is not a claim about this listing.
 *
 * @return list<array{line: int, info: string, marker: string, code: string}>
 */
function fireflyFencedBlocks(string $path): array
{
    $lines = explode("\n", (string) file_get_contents($path));
    $total = count($lines);

    /** @var list<array{line: int, info: string, marker: string, code: string}> $blocks */
    $blocks = [];
    $marker = '';

    for ($index = 0; $index < $total; $index++) {
        $line = $lines[$index];

        if (preg_match('/^\s*<!--\s*(?:source|illustrative):/', $line) === 1) {
            $marker = trim($line);

            continue;
        }

        if (preg_match('/^\s*(`{3,})(.*)$/', $line, $opened) !== 1) {
            if (trim($line) !== '') {
                $marker = '';
            }

            continue;
        }

        $fence = '/^\s*'.preg_quote($opened[1], '/').'\s*$/';
        $start = $index;
        /** @var list<string> $code */
        $code = [];

        while (++$index < $total && preg_match($fence, $lines[$index]) !== 1) {
            $code[] = $lines[$index];
        }

        $blocks[] = [
            'line' => $start + 1,
            'info' => trim($opened[2]),
            'marker' => $marker,
            'code' => implode("\n", $code),
        ];
        $marker = '';
    }

    return $blocks;
}

/**
 * The chapter-opening promise of a manuscript page — the paragraph that starts with $opener — or null.
 *
 * Both editions open every chapter on one: "By the end of this chapter you will know…" and "Al terminar
 * este capítulo…". It is the first sentence a reader of that chapter reads and the only one that claims
 * what the WHOLE chapter covers, which is what makes it the paragraph most worth holding the two editions
 * to and the one that went stale in five chapters at once.
 */
function fireflyChapterPromise(string $path, string $opener): ?string
{
    $split = preg_split('/\n\s*\n/', (string) file_get_contents($path));

    foreach ($split === false ? [] : $split as $paragraph) {
        if (str_starts_with(ltrim($paragraph), $opener)) {
            return $paragraph;
        }
    }

    return null;
}

/**
 * The distinct backticked spans of a paragraph, sorted.
 *
 * An identifier, a config key, an endpoint path or an attribute is written in backticks throughout both
 * manuscripts and — by the promise `book/README.md` makes — is never translated. So the set of backticked
 * spans is the part of a paragraph that must survive translation unchanged, which makes it a claim-for-claim
 * comparison two languages can actually be held to without a translation memory.
 *
 * @return list<string>
 */
function fireflyCodeSpans(string $paragraph): array
{
    preg_match_all('/`([^`\n]+)`/u', $paragraph, $found);

    /** @var list<string> $spans */
    $spans = array_values(array_unique($found[1]));
    sort($spans);

    return $spans;
}

/**
 * Every HTTP route the shipped skeleton declares with an attribute, as the route scan would read them.
 *
 * DERIVED FROM THE SKELETON, because the skeleton is what `composer create-project` hands a reader and what
 * the quick start walks them through. A class-level `#[RequestMapping]` prefixes the method paths exactly as
 * the real scan composes them, a verb attribute with no path of its own lands on the prefix, and the
 * STEREOTYPE is kept as written — `#[RestController]` returns a value the `ResponseFactory` negotiates into
 * JSON, `#[Controller]` renders a view — because telling those two apart is the whole point of the canary
 * that calls this.
 *
 * @return list<array{path: string, stereotype: string, class: string}>
 */
function fireflySkeletonRoutes(): array
{
    /** @var list<array{path: string, stereotype: string, class: string}>|null $routes */
    static $routes = null;

    if ($routes !== null) {
        return $routes;
    }

    /** @var list<array{path: string, stereotype: string, class: string}> $found */
    $found = [];

    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__).'/skeleton/app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($walk as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^#\[(RestController|Controller)\]\s*$/m', $source, $stereotype) !== 1) {
            continue;
        }

        $base = preg_match("/^#\\[RequestMapping\\(\\s*'([^']*)'/m", $source, $mapped) === 1 ? $mapped[1] : '';

        preg_match_all("/#\\[(?:Get|Post|Put|Patch|Delete)Mapping\\(\\s*(?:'([^']*)')?/", $source, $verbs, PREG_SET_ORDER);

        foreach ($verbs as $verb) {
            $path = rtrim($base.($verb[1] ?? ''), '/');

            $found[] = [
                'path' => $path === '' ? '/' : $path,
                'stereotype' => $stereotype[1],
                'class' => $file->getBasename('.php'),
            ];
        }
    }

    usort($found, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

    return $routes = $found;
}

it('pins the "character for character" claim to the blocks the two editions really share', function () {
    // The twenty-third, and the first to hold one manuscript against the other. Wave R's Spanish task closed
    // by deleting the "the Spanish edition trails" caveat — correctly, the trees were level — and replacing
    // it with an absolute: "every listing in the Spanish edition is the English one character for character".
    // That sentence is false, and the task's own report says why: four listings deliberately keep Spanish
    // comments, because a `# escribe el fichero` beside a `composer require` is a sentence a reader READS,
    // not code they run. Four blocks out of several hundred, on the repository's front page, with no test
    // between the claim and the tree it describes.
    //
    // DERIVED, AND IN BOTH DIRECTIONS. The blocks are paired in document order and compared byte for byte:
    // a `php` listing or a `source:`-marked excerpt that differs is a failure, because those are the two
    // things the sentence now promises; a block that differs and is NEITHER is the translated shell comment
    // the sentence now excludes, counted rather than refused. And the sentence itself is read back off the
    // page — a paragraph that says "character for character" without naming what it covers is the absolute
    // this canary exists to keep out, whichever of the two READMEs it is written on.
    $root = dirname(__DIR__);

    $failures = [];
    $pairs = 0;
    $compared = 0;
    /** @var list<string> $translated */
    $translated = [];

    foreach ((array) glob($root.'/book/src/*.md') as $path) {
        $name = basename((string) $path);
        $spanish = $root.'/book/src-es/'.$name;

        if (! is_file($spanish)) {
            continue;
        }

        $pairs++;

        $english = fireflyFencedBlocks((string) $path);
        $edition = fireflyFencedBlocks($spanish);

        if (count($english) !== count($edition)) {
            $failures[] = $name.': the English edition holds '.count($english).' fenced blocks and the Spanish '
                .'one '.count($edition).', so the two no longer pair listing for listing at all';

            continue;
        }

        foreach ($english as $index => $block) {
            $other = $edition[$index];

            $verbatim = str_starts_with($block['info'], 'php')
                || str_starts_with($other['info'], 'php')
                || str_contains($block['marker'], 'source:')
                || str_contains($other['marker'], 'source:');

            if ($verbatim) {
                $compared++;
            }

            if ($block['code'] === $other['code'] && $block['info'] === $other['info']) {
                continue;
            }

            if ($verbatim) {
                $failures[] = 'book/src/'.$name.':'.$block['line'].' and book/src-es/'.$name.':'.$other['line']
                    .' are the same `php` or `source:`-marked listing and their bytes differ — both READMEs '
                    .'promise those are identical, and a `source:` block that differs also stops matching the '
                    .'file it names in one of the two trees';

                continue;
            }

            $translated[] = $name.':'.$block['line'];
        }
    }

    // book/README.md is outside fireflyProsePages() — that walk covers README.md, docs/ and the two
    // manuscripts — and it makes this claim in its own words, which is how the same absolute shipped twice.
    $pages = fireflyProsePages();
    $split = preg_split('/\n\s*\n/', (string) file_get_contents($root.'/book/README.md'));
    $pages['book/README.md'] = $split === false ? [] : $split;

    $claiming = 0;

    foreach ($pages as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (! str_contains($paragraph, 'character for character')) {
                continue;
            }

            $claiming++;

            if (str_contains($paragraph, '`php`') && str_contains($paragraph, '`source:`')) {
                continue;
            }

            $failures[] = $page.' promises the two editions match "character for character" without saying '
                .'which listings that covers, and '.count($translated).' block(s) differ — '
                .implode(', ', $translated).'. Scope the claim to `php` listings and `source:`-marked '
                .'excerpts, which is what this canary compares.';
        }
    }

    expect($failures)->toBe([])
        ->and($pairs)->toBeGreaterThan(0, 'no chapter exists in both trees any more, so this canary holds nothing')
        ->and($compared)->toBeGreaterThan(0, 'no chapter pair shares a `php` or `source:`-marked listing any more, so this canary holds nothing')
        ->and($claiming)->toBeGreaterThan(0, 'no page claims the two editions match character for character any more, so this canary holds nothing');
});

it('pins every Spanish chapter-opening promise to the identifiers the English one names', function () {
    // The twenty-fourth, and the one that catches a whole wave of work stopping one paragraph short. The
    // Spanish task brought five chapter BODIES level with the English ones — query by example, the four
    // method attributes, `timeout:` enforcement, `#[TransactionalEventListener]`, form login and remember-me,
    // histogram buckets, structured logging, the Pest 4 browser suite — and left the five chapter-opening
    // promises above them at their pre-wave text. The result was a chapter that taught material its own first
    // sentence said it would not cover, and the worst of the five told a Spanish reader that `#[PreAuthorize]`
    // "se hace cumplir en el bus de CQRS" and stopped there, which has not been true since method security
    // grew a proxy — a reader would have been told the feature they were about to use does not exist.
    //
    // NOTHING COULD SEE IT. The parity canary above compares per-chapter LINE COUNTS, and five paragraphs are
    // not a line count: chapter 11's ratio never dropped below the threshold at all.
    //
    // DERIVED FROM THE TWO PARAGRAPHS, via the one thing translation may not touch. Identifiers, config keys,
    // endpoint paths and attributes are written in backticks and never translated — book/README.md promises
    // exactly that — so the SET of backticked spans is what the two promises must share. A clause the Spanish
    // opener dropped took `#[EntityGraph]`, `BEFORE_COMMIT`, `firefly.security.http.entry_point`,
    // `histogram_quantile()` and `assertNoJavaScriptErrors()` with it, and every one of those is named in the
    // Spanish chapter BELOW the promise that forgot it.
    $root = dirname(__DIR__);

    $failures = [];
    $judged = 0;

    foreach ((array) glob($root.'/book/src/*.md') as $path) {
        $name = basename((string) $path);
        $spanish = $root.'/book/src-es/'.$name;

        if (! is_file($spanish)) {
            continue;
        }

        $english = fireflyChapterPromise((string) $path, 'By the end of this chapter');
        $translated = fireflyChapterPromise($spanish, 'Al terminar este capítulo');

        if ($english === null && $translated === null) {
            continue;
        }

        if ($english === null || $translated === null) {
            $failures[] = $name.': only one of the two editions opens on a "By the end of this chapter" '
                .'promise, so the chapter no longer tells both readers the same thing about itself';

            continue;
        }

        $judged++;

        $missing = array_values(array_diff(fireflyCodeSpans($english), fireflyCodeSpans($translated)));
        $extra = array_values(array_diff(fireflyCodeSpans($translated), fireflyCodeSpans($english)));

        if ($missing !== []) {
            $failures[] = 'book/src-es/'.$name.': the chapter-opening promise does not name '
                .implode(', ', $missing).', which the English one promises — translate the clause rather '
                .'than dropping it, or the chapter teaches what its first sentence says it will not';
        }

        if ($extra !== []) {
            $failures[] = 'book/src-es/'.$name.': the chapter-opening promise names '.implode(', ', $extra)
                .', which the English one does not — a promise made to one reader only is the bilingual '
                .'shape of the defect this file exists to remove';
        }
    }

    expect($failures)->toBe([])
        ->and($judged)->toBeGreaterThan(0, 'no chapter opens on a promise in both editions any more, so this canary holds nothing');
});

it('pins every quick-start curl transcript to the route the shipped skeleton really serves', function () {
    // The twenty-fifth, and the one whose defect a reader hits in the first ten minutes. The quick start told
    // them to "hit the two routes you just read", printed `curl -s localhost:8000/` and showed
    // `{"message":"Hello, World!"}` coming back. The listing above it shows ONE route, `/greetings/{name}`,
    // and `/` in the shipped skeleton is App\Http\WelcomeController — a `#[Controller]` whose index() returns
    // a view, so the real answer is an HTML page. Both editions carried it at the same line numbers.
    //
    // WORSE, THE FIX WAS ALREADY IN THE FILE. GreetingController's docblock says in so many words that `/`
    // belongs to WelcomeController and renders HTML — and the excerpt's `// …` elision cut exactly those two
    // lines, so the one sentence that would have corrected the reader was the sentence the listing hid.
    //
    // DERIVED FROM THE SKELETON'S ATTRIBUTES. Every `curl … localhost:8000/<path>` is resolved against the
    // routes fireflySkeletonRoutes() reads out of skeleton/app, and the fence of the transcript BESIDE it is
    // held to the stereotype that serves it: `json` needs a `#[RestController]`, `html` needs a `#[Controller]`.
    // A path no skeleton route claims is skipped rather than failed — the actuator, the dashboard and the
    // OpenAPI surface are mounted by the framework and not by an attribute, and those three prefixes are
    // derived below so that a MISSING route is still caught anywhere else.
    $root = dirname(__DIR__);
    $routes = fireflySkeletonRoutes();

    $config = new Config(new ConfigRepository([]));
    $openApi = OpenApiProperties::fromConfig($config);
    // The three surfaces the framework mounts natively, as their own settings objects resolve them with no
    // configuration at all — so the day a default path moves, the exemption moves with it.
    $framework = [
        '/'.ExposureModel::fromConfig($config)->basePath,
        AdminSettings::fromConfig($config)->url(),
        '/'.$openApi->specPath,
        '/'.$openApi->viewerPath,
    ];

    $failures = [];
    $judged = 0;

    foreach (['book/src', 'book/src-es', 'docs'] as $tree) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$tree, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }

            if (str_contains($file->getPathname(), '/docs/superpowers/')) {
                continue;
            }

            $page = substr($file->getPathname(), strlen($root) + 1);
            $blocks = fireflyFencedBlocks($file->getPathname());

            foreach ($blocks as $index => $block) {
                $calls = preg_match_all('/curl\s+[^\n]*localhost:8000(\S*)/', $block['code'], $found);

                // A block holding several calls cannot be tied to the one transcript beside it, so only a
                // single-call block is judged. Every transcript in either manuscript is written that way.
                if ($calls !== 1) {
                    continue;
                }

                // A bare `localhost:8000` (or one with only a query string) is the root, which is exactly
                // the route the quick start got wrong, so it must resolve rather than be skipped.
                $requested = strtok($found[1][0], '?');
                $requested = $requested === false ? '/' : $requested;

                foreach ($framework as $prefix) {
                    if (str_starts_with($requested, $prefix)) {
                        continue 3;
                    }
                }

                /** @var list<array{path: string, stereotype: string, class: string}> $matched */
                $matched = array_values(array_filter(
                    $routes,
                    static fn (array $route): bool => preg_match(
                        '#^'.preg_replace('/\\\\\{[^}]*\\\\\}/', '[^/]+', preg_quote($route['path'], '#')).'$#',
                        $requested,
                    ) === 1,
                ));

                $judged++;

                if ($matched === []) {
                    $failures[] = $page.':'.$block['line'].' calls `localhost:8000'.$requested.'`, and the '
                        .'shipped skeleton serves no such route — it declares '
                        .implode(', ', array_column($routes, 'path'));

                    continue;
                }

                $fence = $blocks[$index + 1]['info'] ?? '';
                $expected = str_starts_with($fence, 'json') ? 'RestController' : null;

                if ($expected === null || in_array($expected, array_column($matched, 'stereotype'), true)) {
                    continue;
                }

                $failures[] = $page.':'.$block['line'].' shows a `json` transcript for `localhost:8000'
                    .$requested.'`, and the skeleton serves that path from '.$matched[0]['class'].', a `#['
                    .$matched[0]['stereotype'].']` — an HTML page, not JSON. Fix the walkthrough rather than '
                    .'the transcript: the reader runs this command.';
            }
        }
    }

    expect($failures)->toBe([])
        ->and($judged)->toBeGreaterThan(0, 'no page walks the reader through a request to the skeleton any more, so this canary holds nothing');
});

it('pins every filter-operator enumeration to the labels DataFilter really declares', function () {
    // The twenty-sixth, and the one that catches a translation going one identifier too far. The Spanish
    // chapter rendered the data browser's eight comparisons as `es`, `no es`, `contiene`, `empieza por`,
    // `mayor que`, `menor que`, `está vacío`, `no está vacío` — invented labels, presented as the ones the
    // reader will see. DataFilter::operators() ships English labels only, the dashboard has no localisation
    // layer at all, and DataFilter::isOperator() DROPS an operator it does not know without a word. A Spanish
    // reader looking for "empieza por" in the dropdown, or typing it into a hand-edited URL, finds nothing.
    // The same paragraph in English had its own smaller version of the defect: `greater` and `less` where the
    // class declares 'greater than' and 'less than'.
    //
    // DERIVED FROM THE CLASS, in both trees at once. A paragraph is judged when it COUNTS the comparisons and
    // ENUMERATES them — a comma-separated run of backticked spans — which is what an operator list looks like
    // in either language and what a recap-table row or a passing reference to "the same eight comparisons" is
    // not. Every label must then be spelled the way operators() declares it, and the count must be the number
    // of operators there really are.
    $operators = DataFilter::operators();
    $labels = array_values($operators);
    sort($labels);

    $failures = [];
    $judged = 0;

    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (preg_match('/([\p{L}\d]+)\s+(?:comparisons|comparaciones)\b/u', $paragraph, $counted) !== 1) {
                continue;
            }

            $written = fireflyWrittenNumber($counted[1]);

            // A run of four or more comma-separated code spans is an enumeration in either language, and is
            // the shape a translated list keeps — which is the point, since a fully translated list contains
            // none of the labels this canary is looking for and would otherwise never be judged at all.
            if ($written === null || preg_match('/`[^`\n]+`(?:\s*,\s*`[^`\n]+`){3,}/u', $paragraph) !== 1) {
                continue;
            }

            $judged++;

            if ($written !== count($operators)) {
                $failures[] = $page.' enumerates '.$counted[1].' data-browser comparisons, and '
                    .'DataFilter::operators() declares '.count($operators);
            }

            $missing = array_values(array_diff($labels, fireflyCodeSpans($paragraph)));

            if ($missing !== []) {
                $failures[] = $page.' enumerates the data browser\'s comparisons without spelling `'
                    .implode('`, `', $missing).'` the way DataFilter::operators() declares '
                    .(count($missing) === 1 ? 'it' : 'them').' — the dashboard ships no localisation, and '
                    .'DataFilter::isOperator() drops an operator it does not know in silence, so a label a '
                    .'reader cannot find in the dropdown is a label that does not exist';
            }
        }
    }

    expect($failures)->toBe([])
        ->and($judged)->toBeGreaterThan(0, 'no page enumerates the data browser\'s comparisons any more, so this canary holds nothing');
});
