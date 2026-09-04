<?php

declare(strict_types=1);

/**
 * No source file may use syntax that PHP 8.3 — the framework's own floor — cannot parse.
 *
 * Every package declares `php: ^8.3`, but a contributor runs whatever their machine has. PHP 8.4 allows
 * `new Foo()->bar()` without parentheses around the constructor, and on 8.3 that is a **parse error**, not a
 * deprecation. So a file using it analyses clean, tests clean and lints clean on 8.5 while being unloadable
 * for a third of the supported range.
 *
 * That is not hypothetical: ten call sites across two test files shipped green locally and turned the 8.3 CI
 * job red with `Parse error: syntax error`, which is the least actionable failure a contributor can be
 * handed — it names a file and a column, and nothing about why the same file is fine on their machine.
 *
 * PHPSTAN CANNOT DO THIS. Its `phpVersion` parameter governs semantic analysis — which functions and
 * behaviours exist — and not the parser, which always reads the newest grammar. `php -l` cannot either,
 * unless the CI runner happens to be on the oldest version, which is exactly the coincidence that let this
 * through. A scan is the only check that runs on every developer's machine regardless of what they have
 * installed.
 */
it('uses no syntax newer than the minimum supported PHP version', function () {
    $root = dirname(__DIR__);
    $offenders = [];

    foreach (['packages', 'skeleton', 'samples', 'tests'] as $directory) {
        $path = $root.'/'.$directory;
        if (! is_dir($path)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            foreach (newWithoutParentheses((string) file_get_contents($file->getPathname())) as $line) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1).':'.$line;
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});

/**
 * Lines holding `new Foo(…)->` — the arrow attached directly to the constructor's own closing parenthesis.
 *
 * TOKENISED, NOT PATTERN-MATCHED, and the first version of this function proved why: a regex over the raw
 * text matched the example inside this very docblock and reported the guard as its own first offender.
 * `token_get_all()` sees code and never comments or string literals, so the scan cannot be fooled by prose
 * that happens to describe the thing it is looking for.
 *
 * Parentheses are brace-matched because a constructor argument may contain its own — `new Money(max(1, $n))`
 * — and counting to the first `)` would report the wrong sites. An already-correct `(new Foo(…))->` is
 * skipped by checking the token before the `new`.
 *
 * @return list<int>
 */
function newWithoutParentheses(string $source): array
{
    $tokens = token_get_all($source);
    $lines = [];

    /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_NEW) {
            continue;
        }

        // Already wrapped: the token before `new` is the opening parenthesis of `(new Foo(…))->`.
        $previous = previousCode($tokens, $index);
        if ($previous === '(') {
            continue;
        }

        $cursor = openingParenthesis($tokens, $index);
        if ($cursor === null) {
            continue;
        }

        $depth = 0;
        for ($i = $cursor; $i < count($tokens); $i++) {
            $current = $tokens[$i];
            if ($current === '(') {
                $depth++;
            } elseif ($current === ')') {
                $depth--;
                if ($depth === 0) {
                    $after = nextCode($tokens, $i);
                    if (is_array($after) && $after[0] === T_OBJECT_OPERATOR) {
                        $lines[] = $token[2];
                    }
                    break;
                }
            }
        }
    }

    return $lines;
}

/**
 * The `(` that opens the constructor's argument list, or null when the `new` is followed by something this
 * scan does not model (an anonymous class, a variable class name with no call).
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function openingParenthesis(array $tokens, int $from): ?int
{
    for ($i = $from + 1; $i < count($tokens); $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
            continue;
        }

        return $token === '(' ? $i : null;
    }

    return null;
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array{0: int, 1: string, 2: int}|string|null
 */
function previousCode(array $tokens, int $from): array|string|null
{
    for ($i = $from - 1; $i >= 0; $i--) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $tokens[$i];
    }

    return null;
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array{0: int, 1: string, 2: int}|string|null
 */
function nextCode(array $tokens, int $from): array|string|null
{
    for ($i = $from + 1; $i < count($tokens); $i++) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $tokens[$i];
    }

    return null;
}
