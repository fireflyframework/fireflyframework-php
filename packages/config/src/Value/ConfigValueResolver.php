<?php

declare(strict_types=1);

namespace Firefly\Config\Value;

use Firefly\Config\Config;
use Firefly\Container\Value\ValueResolver;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * A config-backed ValueResolver: ${key:default} resolves against application config first, then the
 * environment, then the default. #{expr} is evaluated with a config('key') function available. Bound over
 * firefly/container's DefaultValueResolver so #[Value] injection reads real application config.
 */
final class ConfigValueResolver implements ValueResolver
{
    private ExpressionLanguage $expressionLanguage;

    public function __construct(
        private readonly Config $config,
        ?ExpressionLanguage $expressionLanguage = null,
    ) {
        $this->expressionLanguage = $expressionLanguage ?? new ExpressionLanguage;
        $this->expressionLanguage->register(
            'config',
            static fn (string $key): string => sprintf('$config->get(%s)', $key),
            fn (array $vars, string $key): mixed => $this->config->get($key),
        );
    }

    public function resolve(string $expression): mixed
    {
        if (preg_match('/^\$\{([^:}]+)(?::([^}]*))?\}$/', $expression, $m) === 1) {
            $key = $m[1];
            if ($this->config->has($key)) {
                return $this->config->get($key);
            }
            $env = getenv($key);
            if ($env !== false) {
                return $env;
            }

            return array_key_exists(2, $m) ? $m[2] : null;
        }

        if (preg_match('/^#\{(.*)\}$/s', $expression, $m) === 1) {
            return $this->expressionLanguage->evaluate($m[1], ['config' => $this->config]);
        }

        return $expression;
    }
}
