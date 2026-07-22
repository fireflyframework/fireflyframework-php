<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Query;

/**
 * One parsed predicate: the snake_case column, the operator token (see DerivedQueryParser::OPERATORS, plus the
 * default `Equals`), whether it is IgnoreCase, and the baked boolean for the zero-arg `True`/`False` operators.
 */
final readonly class Predicate
{
    public function __construct(
        public string $field,
        public string $op,
        public bool $ignoreCase,
        public ?bool $boolLiteral,
    ) {}

    /**
     * @return array{field: string, op: string, ignoreCase: bool, boolLiteral: bool|null}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'op' => $this->op,
            'ignoreCase' => $this->ignoreCase,
            'boolLiteral' => $this->boolLiteral,
        ];
    }
}
