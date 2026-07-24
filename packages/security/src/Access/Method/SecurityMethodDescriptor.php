<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

/**
 * One compiled method-security rule: the guarded class + method, the normalised boolean expression the evaluator
 * runs, and the ordered parameter names (so #param references bind positional call args by name at enforcement).
 * Every field is scalar/array so the manifest var_exports as a plain array literal loaded by require+map.
 *
 * @phpstan-type SecurityMethodRow array{class: string, method: string, expression: string, params: list<string>}
 */
final readonly class SecurityMethodDescriptor
{
    /**
     * @param  list<string>  $params
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $expression,
        public array $params,
    ) {}

    public function key(): string
    {
        return $this->class.'::'.$this->method;
    }

    /**
     * @return SecurityMethodRow
     */
    public function toArray(): array
    {
        return ['class' => $this->class, 'method' => $this->method, 'expression' => $this->expression, 'params' => $this->params];
    }

    /**
     * @param  SecurityMethodRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['class'], $data['method'], $data['expression'], $data['params']);
    }
}
