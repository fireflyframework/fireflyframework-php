<?php

declare(strict_types=1);

namespace Firefly\Container\Value;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Resolves:
 *  - ${NAME:default} — an environment variable, falling back to `default` (or null if absent).
 *  - #{expr}         — a sandboxed expression (symfony/expression-language).
 *  - anything else   — returned as a literal string.
 *
 * firefly/config (M3) provides a config-backed ValueResolver that also reads application config.
 */
final class DefaultValueResolver implements ValueResolver
{
    private ExpressionLanguage $expressionLanguage;

    public function __construct(?ExpressionLanguage $expressionLanguage = null)
    {
        $this->expressionLanguage = $expressionLanguage ?? new ExpressionLanguage;
    }

    public function resolve(string $expression): mixed
    {
        if (preg_match('/^\$\{([^:}]+)(?::([^}]*))?\}$/', $expression, $m) === 1) {
            $env = getenv($m[1]);
            if ($env !== false) {
                return $env;
            }

            return array_key_exists(2, $m) ? $m[2] : null;
        }

        if (preg_match('/^#\{(.*)\}$/s', $expression, $m) === 1) {
            return $this->expressionLanguage->evaluate($m[1]);
        }

        return $expression;
    }
}
