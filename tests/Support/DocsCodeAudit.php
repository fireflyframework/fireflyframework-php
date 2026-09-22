<?php

// tests/Support/DocsCodeAudit.php

declare(strict_types=1);

namespace Firefly\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A code listing in documentation is a promise, and until this class existed nothing in the repository ever
 * checked one. The README carried nine `<!-- source: … -->` comments that no test read; six of the nine did
 * not match the file they named. `book/build/verify_code.py` linted the book's listings with `php -l`, which
 * proves a listing PARSES and says nothing about whether the API it shows exists. A plausible-looking listing
 * that no file backs is the single failure mode that makes documentation worse than none, because a reader
 * cannot tell it from the rest.
 *
 * So every fenced block in an audited file is held to exactly one of three contracts:
 *
 *   (a) PROVENANCE — a `php` block preceded by `<!-- source: <repo-relative-path> -->`. Its code must appear
 *       VERBATIM in that file: the same lines, in the same order, with the same relative indentation, allowing
 *       one constant indentation offset (a method excerpted out of its class is written at column 0) and
 *       allowing whole lines to be cut where the listing says so with a line that is exactly ELISION.
 *
 *   (b) SHAPE — anything that is not `php`: shell, json, yaml, ini, text. There is nothing to compare
 *       verbatim, so what it ASSERTS is checked instead: every `firefly.*` key it names must be a key the
 *       framework really reads or the reference really documents, every `php artisan firefly:*` /
 *       `make:firefly-*` command must be a $signature that exists, and every `composer <script>` must be a
 *       script in composer.json or skeleton/composer.json (or one of Composer's own commands).
 *
 *   (c) ILLUSTRATIVE — a `php` block preceded by `<!-- illustrative: <why> -->`. This is for code that CANNOT
 *       exist in this repository: an application's own class, the one a reader would write. It is still not a
 *       free pass — the listing must parse, every `use Firefly\…;` it writes must name a real class, and every
 *       `#[Attribute]` must be a real framework attribute or one the listing itself imports.
 *
 * Rule (b) also runs over `php` blocks, because a config array in a listing is exactly where a stale key hides.
 *
 * ONE THING THIS CANNOT SEE, stated so nobody trusts it further than it goes: the key check matches the DOTTED
 * form (`firefly.security.enabled`), so a config listing written as a nested PHP array names no key this class
 * can find. That is not a hole in practice, because a listing showing the framework's configuration is a
 * `source:` block against skeleton/config/firefly.php and is therefore compared verbatim against the reference
 * itself — which is a stronger check than this one. What is genuinely outside the guard is a `firefly.*` key
 * written in PROSE or in a module guide's configuration TABLE; those are checked by hand, once, in wave R's
 * module-docs task.
 *
 * The audited surface is a list, not "everything", and it only ever GROWS: wave R turned the guard on one
 * surface at a time so that every commit could be green, and its last task asserts the list reaches every
 * Markdown file the walk can see. Removing an entry is never a fix.
 */
final class DocsCodeAudit
{
    /**
     * The one elided-lines marker: `//` followed by U+2026. A `source:` listing may cut whole lines anywhere
     * it likes as long as it says so with a line that trims to exactly this, and the guard then resumes its
     * search AFTER what it has already matched — so the segments must still appear in the file in order.
     */
    public const string ELISION = '// …';

    /**
     * Repo-relative files and directories whose every fenced block is audited.
     *
     * @var list<string>
     */
    public const array AUDITED = [
        'README.md',
    ];

    /**
     * Prefixes whose NEXT segment is chosen by the application, not by the framework: a feature switch's own
     * name, a registration id, a provider id, an in-memory user's name, an actuator endpoint id, a health
     * group's name. For these the guard checks the TAIL after the user's segment instead — so
     * `firefly.security.oauth2.client.registration.google.client-id` is accepted because some real key ends
     * in `.client-id` under that prefix, while a misspelt tail is still caught.
     *
     * @var list<string>
     */
    public const array USER_KEY_PREFIXES = [
        'firefly.feature.',
        'firefly.management.endpoint.',
        'firefly.management.endpoint.health.group.',
        'firefly.security.oauth2.client.provider.',
        'firefly.security.oauth2.client.registration.',
        'firefly.security.users.',
    ];

    /** @var list<string> */
    public const array PHP_LANGUAGES = ['php'];

    /** @var list<string> */
    public const array SHELL_LANGUAGES = ['bash', 'sh', 'shell', 'console'];

    /**
     * Composer's own commands. Anything else after `composer` must be a script this repository or the
     * skeleton declares.
     *
     * @var list<string>
     */
    public const array COMPOSER_BUILTINS = [
        'archive', 'audit', 'browse', 'bump', 'clear-cache', 'config', 'create-project', 'depends',
        'diagnose', 'dump-autoload', 'dumpautoload', 'exec', 'fund', 'global', 'init', 'install',
        'licenses', 'outdated', 'prohibits', 'reinstall', 'remove', 'require', 'search', 'self-update',
        'show', 'status', 'suggests', 'update', 'validate', 'why', 'why-not',
    ];

    /**
     * PHP's own attributes, which resolve to no class under packages/*\/src and are never imported.
     *
     * @var list<string>
     */
    public const array PHP_ATTRIBUTES = [
        'AllowDynamicProperties', 'Attribute', 'Deprecated', 'Override', 'ReturnTypeWillChange',
        'SensitiveParameter',
    ];

    /** @var list<string>|null */
    private ?array $keyLiterals = null;

    /** @var list<string>|null */
    private ?array $frameworkCommands = null;

    /** @var list<string>|null */
    private ?array $composerScripts = null;

    /** @var array{fqcn: array<string, true>, short: array<string, true>}|null */
    private ?array $symbols = null;

    public function __construct(private readonly string $root) {}

    /**
     * @return list<string>
     */
    public function markdownFiles(): array
    {
        $files = [];

        foreach (self::AUDITED as $entry) {
            $absolute = $this->root.'/'.$entry;

            if (is_file($absolute)) {
                $files[] = $entry;

                continue;
            }

            if (! is_dir($absolute)) {
                throw new RuntimeException("DocsCodeAudit::AUDITED names a path that does not exist: {$entry}");
            }

            /** @var iterable<SplFileInfo> $found */
            $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($found as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'md') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($this->root) + 1);

                if (str_starts_with($relative, 'docs/superpowers/')) {
                    continue;
                }

                $files[] = $relative;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<DocsCodeBlock>
     */
    public function blocksIn(string $relative): array
    {
        $lines = $this->linesOf((string) file_get_contents($this->root.'/'.$relative));
        $count = count($lines);
        $blocks = [];

        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^\s*```\s*([A-Za-z0-9.+_-]*)\s*$/', $lines[$i], $open) !== 1) {
                continue;
            }

            $fence = $i + 1;
            $above = $i - 1;
            $body = [];
            $i++;

            while ($i < $count && preg_match('/^\s*```\s*$/', $lines[$i]) !== 1) {
                $body[] = $lines[$i];
                $i++;
            }

            [$source, $illustrative] = $this->markersAbove($lines, $above);

            $blocks[] = new DocsCodeBlock($relative, $fence, strtolower($open[1]), implode("\n", $body), $source, $illustrative);
        }

        return $blocks;
    }

    public function verify(DocsCodeBlock $block): ?string
    {
        if (in_array($block->language, self::PHP_LANGUAGES, true)) {
            return $this->verifyPhp($block);
        }

        return $this->verifyShape($block);
    }

    private function verifyPhp(DocsCodeBlock $block): ?string
    {
        if ($block->source !== null) {
            return $this->verifyProvenance($block)
                ?? $this->verifySymbols($block)
                ?? $this->verifyShape($block);
        }

        if ($block->illustrative !== null) {
            if (strlen($block->illustrative) < 12) {
                return 'is marked illustrative with no reason a reader could act on: `'.$block->illustrative.'`.';
            }

            return $this->verifyLints($block)
                ?? $this->verifySymbols($block)
                ?? $this->verifyShape($block);
        }

        return 'is a php listing with no provenance. Put `<!-- source: <repo-relative-path> -->` above it and '
            .'make the listing verbatim (cut whole lines with a line that is exactly `'.self::ELISION.'`), or '
            .'`<!-- illustrative: <why this code cannot exist in the repository> -->` if it is a class the '
            .'reader writes in their own application.';
    }

    private function verifyProvenance(DocsCodeBlock $block): ?string
    {
        $path = (string) $block->source;

        if (str_contains($path, ',')) {
            return 'names more than one source file (`'.$path.'`): one fenced block shows one file — split it.';
        }

        if (str_ends_with($path, '.md')) {
            return 'names a Markdown file as its source (`'.$path.'`): a document is not evidence, point at code.';
        }

        if (! is_file($this->root.'/'.$path)) {
            return 'names a source that does not exist: `'.$path.'`.';
        }

        $haystack = $this->linesOf((string) file_get_contents($this->root.'/'.$path));
        $cursor = 0;

        foreach ($this->segments($block->code) as $segment) {
            $after = $this->findSegment($haystack, $segment, $cursor);

            if ($after === null) {
                return 'is not verbatim in `'.$path.'`: the first line that does not match, in order, is '
                    .var_export($segment[0], true).'. Copy the lines as the file has them, or cut whole lines '
                    .'and mark the cut with a line that is exactly `'.self::ELISION.'`.';
            }

            $cursor = $after;
        }

        return null;
    }

    private function verifyLints(DocsCodeBlock $block): ?string
    {
        $code = preg_match('/^\s*<\?php/', $block->code) === 1 ? $block->code : "<?php\n".$block->code;
        $file = (string) tempnam(sys_get_temp_dir(), 'firefly-docs-');
        file_put_contents($file, $code);

        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);
        unlink($file);

        if ($status === 0) {
            return null;
        }

        return 'does not parse: '.trim(str_replace($file, '<listing>', implode(' ', $output)));
    }

    private function verifySymbols(DocsCodeBlock $block): ?string
    {
        $symbols = $this->frameworkSymbols();

        preg_match_all('/^\s*use\s+(Firefly\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+\s*)?;/m', $block->code, $imports);

        foreach ($imports[1] as $import) {
            if (! isset($symbols['fqcn'][$import])) {
                return 'imports a framework class that does not exist: `'.$import.'`.';
            }
        }

        preg_match_all('/^\s*use\s+(?:[A-Za-z0-9_\\\\]+\\\\)?(\w+)\s*;/m', $block->code, $plain);
        preg_match_all('/^\s*use\s+[A-Za-z0-9_\\\\]+\s+as\s+(\w+)\s*;/m', $block->code, $aliased);
        preg_match_all('/^\s*use\s+[A-Za-z0-9_\\\\]+\\\\\{([^}]+)\}\s*;/m', $block->code, $grouped);

        $imported = array_merge($plain[1], $aliased[1], self::PHP_ATTRIBUTES);

        foreach ($grouped[1] as $group) {
            foreach (explode(',', $group) as $member) {
                $imported[] = trim($member);
            }
        }

        $known = array_flip($imported);

        preg_match_all('/#\[\s*\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)/', $block->code, $used);

        foreach (array_unique($used[1]) as $attribute) {
            $short = str_contains($attribute, '\\')
                ? substr($attribute, (int) strrpos($attribute, '\\') + 1)
                : $attribute;

            if (isset($known[$short]) || isset($symbols['short'][$short])) {
                continue;
            }

            return 'uses #['.$attribute.'], which is neither a class under packages/*/src nor imported by the listing.';
        }

        return null;
    }

    private function verifyShape(DocsCodeBlock $block): ?string
    {
        return $this->verifyConfigKeys($block)
            ?? $this->verifyArtisan($block)
            ?? $this->verifyComposer($block);
    }

    private function verifyConfigKeys(DocsCodeBlock $block): ?string
    {
        preg_match_all('/firefly\.[a-z0-9_-]+(?:\.[a-z0-9_*-]+)*/', $block->code, $matches);

        foreach (array_unique($matches[0]) as $match) {
            $key = rtrim($match, '.-_');

            // `config/firefly.php` is the reference file's own name, not a key.
            if ($key === 'firefly.php' || $this->keyIsReal($key)) {
                continue;
            }

            return 'names the configuration key `'.$key.'`, which no file under packages/*/src and no line of '
                .'skeleton/config/firefly.php spells. Either the key is wrong, or its prefix belongs in '
                .'DocsCodeAudit::USER_KEY_PREFIXES because the next segment is the application\'s to choose.';
        }

        return null;
    }

    private function verifyArtisan(DocsCodeBlock $block): ?string
    {
        preg_match_all('/\bartisan\s+([a-z][a-z0-9:_-]*)/', $block->code, $matches);

        foreach (array_unique($matches[1]) as $command) {
            // Laravel's own commands are not this repository's to assert. The framework's are.
            if (! str_starts_with($command, 'firefly:') && ! str_starts_with($command, 'make:firefly-')) {
                continue;
            }

            if (in_array($command, $this->frameworkCommands(), true)) {
                continue;
            }

            return 'runs `php artisan '.$command.'`, which no $signature under packages/*/src declares.';
        }

        return null;
    }

    private function verifyComposer(DocsCodeBlock $block): ?string
    {
        preg_match_all('/\bcomposer\s+(?:run\s+|run-script\s+)?([A-Za-z][\w:.-]*)/', $block->code, $matches);

        foreach (array_unique($matches[1]) as $token) {
            if (in_array($token, self::COMPOSER_BUILTINS, true) || in_array($token, $this->composerScripts(), true)) {
                continue;
            }

            return 'runs `composer '.$token.'`, which is neither a Composer command nor a script in '
                .'composer.json or skeleton/composer.json.';
        }

        return null;
    }

    private function keyIsReal(string $key): bool
    {
        $literals = $this->keyLiterals();

        if (in_array($key, $literals, true)) {
            return true;
        }

        foreach (self::USER_KEY_PREFIXES as $prefix) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $rest = substr($key, strlen($prefix));

            // The user's own segment, and nothing after it: firefly.feature.new.
            if ($rest === '' || ! str_contains($rest, '.')) {
                return true;
            }

            $tail = substr($rest, (int) strpos($rest, '.') + 1);

            foreach ($literals as $literal) {
                if (str_ends_with($literal, '.'.$tail)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: ?string, 1: ?string}
     */
    private function markersAbove(array $lines, int $index): array
    {
        while ($index >= 0 && trim($lines[$index]) === '') {
            $index--;
        }

        if ($index < 0) {
            return [null, null];
        }

        $line = trim($lines[$index]);

        if (preg_match('/^<!--\s*source:\s*(\S.*?)\s*-->$/', $line, $source) === 1) {
            return [$source[1], null];
        }

        if (preg_match('/^<!--\s*illustrative:\s*(\S.*?)\s*-->$/', $line, $illustrative) === 1) {
            return [null, $illustrative[1]];
        }

        return [null, null];
    }

    /**
     * The listing, split on ELISION lines into segments that must each appear verbatim, in order. Every
     * segment is right-trimmed, stripped of blank edges and dedented, so an excerpt written at column 0 still
     * matches a method that lives four spaces in.
     *
     * @return list<list<string>>
     */
    private function segments(string $code): array
    {
        $raw = [];
        $current = [];

        foreach (explode("\n", $code) as $line) {
            if (trim($line) === self::ELISION) {
                $raw[] = $current;
                $current = [];

                continue;
            }

            $current[] = rtrim($line);
        }

        $raw[] = $current;

        $segments = [];

        foreach ($raw as $segment) {
            $segment = $this->trimBlankEdges($segment);

            if ($segment !== []) {
                $segments[] = $this->dedent($segment);
            }
        }

        return $segments;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $segment
     */
    private function findSegment(array $haystack, array $segment, int $from): ?int
    {
        $height = count($segment);
        $last = count($haystack) - $height;
        $own = strlen($segment[0]) - strlen(ltrim($segment[0], ' '));

        for ($start = max($from, 0); $start <= $last; $start++) {
            $offset = strlen($haystack[$start]) - strlen(ltrim($haystack[$start], ' ')) - $own;

            if ($offset < 0) {
                continue;
            }

            $pad = str_repeat(' ', $offset);
            $matched = true;

            for ($k = 0; $k < $height; $k++) {
                $want = $segment[$k];
                $have = $haystack[$start + $k];

                if ($want === '' ? $have !== '' : $have !== $pad.$want) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                return $start + $height;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function trimBlankEdges(array $lines): array
    {
        while ($lines !== [] && $lines[0] === '') {
            array_shift($lines);
        }

        while ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        // array_shift()/array_pop() both keep the array a list, so no re-indexing is needed here.
        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function dedent(array $lines): array
    {
        $indent = PHP_INT_MAX;

        foreach ($lines as $line) {
            if ($line !== '') {
                $indent = min($indent, strlen($line) - strlen(ltrim($line, ' ')));
            }
        }

        if ($indent === PHP_INT_MAX || $indent === 0) {
            return $lines;
        }

        return array_map(
            static fn (string $line): string => $line === '' ? '' : substr($line, $indent),
            $lines,
        );
    }

    /**
     * @return list<string>
     */
    private function linesOf(string $text): array
    {
        return array_map(
            static fn (string $line): string => rtrim($line),
            explode("\n", str_replace("\r\n", "\n", $text)),
        );
    }

    /**
     * @return list<string>
     */
    private function keyLiterals(): array
    {
        if ($this->keyLiterals !== null) {
            return $this->keyLiterals;
        }

        $literals = [];

        foreach ([...$this->phpFilesUnder('packages/*/src'), ...$this->phpFilesUnder('skeleton')] as $file) {
            preg_match_all('/firefly\.[a-z0-9_.-]*[a-z0-9_-]/', (string) file_get_contents($file), $matches);

            foreach ($matches[0] as $literal) {
                $literals[$literal] = true;
            }
        }

        return $this->keyLiterals = array_keys($literals);
    }

    /**
     * @return list<string>
     */
    private function frameworkCommands(): array
    {
        if ($this->frameworkCommands !== null) {
            return $this->frameworkCommands;
        }

        $commands = [];

        foreach ($this->phpFilesUnder('packages/*/src') as $file) {
            $contents = (string) file_get_contents($file);

            preg_match_all("/protected\s+\\\$signature\s*=\s*'([^'\s{]+)/", $contents, $signatures);
            preg_match_all("/protected\s+\\\$name\s*=\s*'([^']+)'/", $contents, $names);

            foreach ([...$signatures[1], ...$names[1]] as $command) {
                $commands[$command] = true;
            }
        }

        return $this->frameworkCommands = array_keys($commands);
    }

    /**
     * @return list<string>
     */
    private function composerScripts(): array
    {
        if ($this->composerScripts !== null) {
            return $this->composerScripts;
        }

        $scripts = [];

        foreach (['composer.json', 'skeleton/composer.json'] as $manifest) {
            $decoded = json_decode((string) file_get_contents($this->root.'/'.$manifest), true);

            if (is_array($decoded) && isset($decoded['scripts']) && is_array($decoded['scripts'])) {
                foreach (array_keys($decoded['scripts']) as $script) {
                    $scripts[(string) $script] = true;
                }
            }
        }

        return $this->composerScripts = array_keys($scripts);
    }

    /**
     * @return array{fqcn: array<string, true>, short: array<string, true>}
     */
    private function frameworkSymbols(): array
    {
        if ($this->symbols !== null) {
            return $this->symbols;
        }

        $fqcn = [];
        $short = [];

        foreach ($this->phpFilesUnder('packages/*/src') as $file) {
            $contents = (string) file_get_contents($file);
            preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace);
            $prefix = isset($namespace[1]) ? trim($namespace[1]) : '';

            preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $contents, $declared);

            foreach ($declared[1] as $name) {
                $fqcn[$prefix.'\\'.$name] = true;
                $short[$name] = true;
            }
        }

        return $this->symbols = ['fqcn' => $fqcn, 'short' => $short];
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $glob): array
    {
        $files = [];

        foreach (glob($this->root.'/'.$glob) ?: [] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $found */
            $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($found as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
