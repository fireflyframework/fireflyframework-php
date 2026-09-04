<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Expression;

/**
 * A whitelist-only SpEL-subset evaluator built by hand — a tokenizer plus a recursive-descent parser that walks
 * a fixed grammar (boolean and/or/not over a CLOSED set of eight function calls against the SecurityExpressionRoot,
 * with string-literal and #param arguments). It NEVER uses eval/create_function/call_user_func: function dispatch
 * is a hard-coded match on the name, and an unknown name or any syntax error raises ExpressionParseException, which
 * evaluate() turns into a fail-closed `false`. This is the deliberate, provably-safe alternative to running
 * symfony/expression-language's evaluate() (or any PHP eval) on annotation strings.
 *
 * @phpstan-type Token array{type: string, value: string}
 */
final class SecurityExpressionEvaluator
{
    private const KEYWORDS = ['and', 'or', 'not', 'true', 'false'];

    /**
     * Hard cap on the raw expression's byte length, checked before tokenize() allocates anything.
     *
     * This bounds both tokenizer memory (a naive `str_repeat('(', N)` would otherwise allocate an
     * O(N) token array) and parser recursion depth (nested parens/`not` recurse one stack frame per
     * char), so an over-long expression cannot exhaust memory or the call stack. A real `#[PreAuthorize]`
     * expression is a short, developer-authored constant — nothing legitimate is anywhere near this
     * limit. Rejecting with ExpressionParseException (a \Throwable) instead of letting a raw allocation
     * fatal keeps evaluate()'s fail-closed guarantee airtight: a fatal error is NOT a \Throwable and
     * would otherwise escape the catch block below.
     */
    private const MAX_EXPRESSION_LENGTH = 2048;

    /** @var list<Token> */
    private array $tokens = [];

    private int $pos = 0;

    private ?SecurityExpressionRoot $root = null;

    public function evaluate(string $expression, SecurityExpressionRoot $root): bool
    {
        // RE-ENTRANCY (fail-open fix). This class is a Singleton bean whose parse state ($tokens/$pos/$root)
        // lives on the instance, and hasPermission() is a dispatch into APPLICATION code — a user-supplied
        // PermissionEvaluator, the extension point the book recommends — which may evaluate an expression of
        // its own on this very object. The inner call used to clobber the outer state, so on return the outer
        // parseExpression() resumed against the inner token stream, immediately saw eof, and returned the
        // INNER result: every term after hasPermission(...) was silently dropped. That fails OPEN —
        // "hasPermission(#id,'read') and hasRole('ADMIN')" granted access to a principal with no ROLE_ADMIN.
        //
        // Saving and restoring around the call makes nested evaluation correct without restructuring the
        // recursive-descent parser. finally runs on the fail-closed catch path too, so a throwing inner
        // evaluator cannot leave torn state behind for the next caller either.
        $outerTokens = $this->tokens;
        $outerPos = $this->pos;
        $outerRoot = $this->root;

        try {
            $this->tokens = $this->tokenize($expression);
            $this->pos = 0;
            $this->root = $root;
            $result = $this->parseExpression();
            $this->expect('eof');

            return $result;
        } catch (\Throwable) {
            // Fail-closed on ANY error: a malformed/hostile expression (ExpressionParseException) OR a throw from
            // deeper in evaluation (e.g. a custom PermissionEvaluator, #param resolution) denies with a clean 403
            // rather than surfacing a 500. Security errs to deny, never to allow.
            return false;
        } finally {
            $this->tokens = $outerTokens;
            $this->pos = $outerPos;
            $this->root = $outerRoot;
        }
    }

    /** Validate syntax + whitelist without a root (build-time). Throws on any problem. */
    public function parse(string $expression): void
    {
        // Same save/restore discipline as evaluate(): parse() is reachable from boot-time validation while an
        // evaluation is in flight, and must not strand the caller's parse state.
        $outerTokens = $this->tokens;
        $outerPos = $this->pos;
        $outerRoot = $this->root;

        try {
            $this->tokens = $this->tokenize($expression);
            $this->pos = 0;
            $this->root = null; // parse-only: calls short-circuit to a dummy bool
            $this->parseExpression();
            $this->expect('eof');
        } finally {
            $this->tokens = $outerTokens;
            $this->pos = $outerPos;
            $this->root = $outerRoot;
        }
    }

    /**
     * @return list<Token>
     */
    private function tokenize(string $expression): array
    {
        if (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            throw new ExpressionParseException('Expression exceeds the maximum permitted length.');
        }

        /** @var list<Token> $tokens */
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if ($char === '(' || $char === ')' || $char === ',' || $char === '#') {
                $tokens[] = ['type' => $char, 'value' => $char];
                $i++;

                continue;
            }

            if ($char === '&' || $char === '|') {
                if ($i + 1 >= $length || $expression[$i + 1] !== $char) {
                    throw new ExpressionParseException("Unexpected character '{$char}'.");
                }
                $tokens[] = ['type' => $char === '&' ? 'and' : 'or', 'value' => $char.$char];
                $i += 2;

                continue;
            }

            if ($char === '!') {
                $tokens[] = ['type' => 'not', 'value' => '!'];
                $i++;

                continue;
            }

            if ($char === "'") {
                $end = strpos($expression, "'", $i + 1);
                if ($end === false) {
                    throw new ExpressionParseException('Unterminated string literal.');
                }
                $tokens[] = ['type' => 'string', 'value' => substr($expression, $i + 1, $end - $i - 1)];
                $i = $end + 1;

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $j = $i + 1;
                while ($j < $length && (ctype_alnum($expression[$j]) || $expression[$j] === '_')) {
                    $j++;
                }
                $word = substr($expression, $i, $j - $i);
                $tokens[] = in_array($word, self::KEYWORDS, true)
                    ? ['type' => $word, 'value' => $word]
                    : ['type' => 'ident', 'value' => $word];
                $i = $j;

                continue;
            }

            throw new ExpressionParseException("Unexpected character '{$char}'.");
        }

        $tokens[] = ['type' => 'eof', 'value' => ''];

        return $tokens;
    }

    private function parseExpression(): bool
    {
        $left = $this->parseAnd();
        while ($this->current()['type'] === 'or') {
            $this->advance();
            $right = $this->parseAnd();
            $left = $left || $right;
        }

        return $left;
    }

    private function parseAnd(): bool
    {
        $left = $this->parseNot();
        while ($this->current()['type'] === 'and') {
            $this->advance();
            $right = $this->parseNot();
            $left = $left && $right;
        }

        return $left;
    }

    private function parseNot(): bool
    {
        if ($this->current()['type'] === 'not') {
            $this->advance();

            return ! $this->parseNot();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): bool
    {
        $token = $this->current();

        if ($token['type'] === '(') {
            $this->advance();
            $value = $this->parseExpression();
            $this->expect(')');

            return $value;
        }
        if ($token['type'] === 'true') {
            $this->advance();

            return true;
        }
        if ($token['type'] === 'false') {
            $this->advance();

            return false;
        }
        if ($token['type'] === 'ident') {
            return (bool) $this->parseCall();
        }

        throw new ExpressionParseException("Unexpected token '{$token['value']}'.");
    }

    private function parseCall(): bool
    {
        $name = $this->current()['value'];
        $this->advance();
        $this->expect('(');

        /** @var list<mixed> $args */
        $args = [];
        if ($this->current()['type'] !== ')') {
            $args[] = $this->parseArg();
            while ($this->current()['type'] === ',') {
                $this->advance();
                $args[] = $this->parseArg();
            }
        }
        $this->expect(')');

        return $this->dispatch($name, $args);
    }

    private function parseArg(): mixed
    {
        $token = $this->current();

        if ($token['type'] === 'string') {
            $this->advance();

            return $token['value'];
        }
        if ($token['type'] === '#') {
            $this->advance();
            $ref = $this->current();
            if ($ref['type'] !== 'ident') {
                throw new ExpressionParseException('Expected an identifier after #.');
            }
            $this->advance();

            return $this->root?->arg($ref['value']);
        }
        if ($token['type'] === 'ident') {
            return $this->parseCall();
        }

        throw new ExpressionParseException("Unexpected argument '{$token['value']}'.");
    }

    /**
     * @param  list<mixed>  $args
     */
    private function dispatch(string $name, array $args): bool
    {
        // parse()-only mode has no root: validate the whitelist but return a placeholder bool.
        $root = $this->root;
        if ($root === null) {
            return match ($name) {
                'hasRole', 'hasAnyRole', 'hasAuthority', 'hasAnyAuthority', 'hasPermission',
                'isAuthenticated', 'permitAll', 'denyAll' => true,
                default => throw new ExpressionParseException("Unknown function '{$name}'."),
            };
        }

        return match ($name) {
            'hasRole' => $root->hasRole($this->str($args, 0, $name)),
            'hasAnyRole' => $root->hasAnyRole(...$this->strings($args, $name)),
            'hasAuthority' => $root->hasAuthority($this->str($args, 0, $name)),
            'hasAnyAuthority' => $root->hasAnyAuthority(...$this->strings($args, $name)),
            'hasPermission' => $root->hasPermission($args[0] ?? null, $this->str($args, 1, $name)),
            'isAuthenticated' => $root->isAuthenticated(),
            'permitAll' => $root->permitAll(),
            'denyAll' => $root->denyAll(),
            default => throw new ExpressionParseException("Unknown function '{$name}'."),
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    private function str(array $args, int $index, string $fn): string
    {
        $value = $args[$index] ?? throw new ExpressionParseException("{$fn}() is missing argument ".($index + 1).'.');

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : throw new ExpressionParseException("{$fn}() argument ".($index + 1).' must be a string.'));
    }

    /**
     * @param  list<mixed>  $args
     * @return list<string>
     */
    private function strings(array $args, string $fn): array
    {
        if ($args === []) {
            throw new ExpressionParseException("{$fn}() requires at least one argument.");
        }

        return array_map(fn (int $i): string => $this->str($args, $i, $fn), array_keys($args));
    }

    /**
     * @return Token
     */
    private function current(): array
    {
        return $this->tokens[$this->pos] ?? ['type' => 'eof', 'value' => ''];
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function expect(string $type): void
    {
        if ($this->current()['type'] !== $type) {
            throw new ExpressionParseException("Expected '{$type}', got '{$this->current()['type']}'.");
        }
        if ($type !== 'eof') {
            $this->advance();
        }
    }
}
