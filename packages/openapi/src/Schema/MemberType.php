<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use ReflectionNamedType;
use ReflectionParameter;

/**
 * The reflected facts about one DTO member that a rule list cannot supply. A tiny local value object rather
 * than four parallel arrays threaded through three methods (DtoSchemaFactory is the only caller).
 *
 * `required()` is the interesting one, and it is deliberately WIDER than the constraint-derived answer. A
 * constructor parameter with no default whose type does not admit null cannot be omitted: ArgumentResolver
 * splats only the keys the body actually carried, so a missing one raises ArgumentCountError inside
 * `new $dto(...)` — a 500, after validation has already passed. Documenting such a member as optional would
 * hand every generated client a legal-looking request that the server cannot serve, so the PHP signature is
 * treated as the requirement it genuinely is, alongside whatever #[NotNull]/#[NotBlank] say.
 */
final readonly class MemberType
{
    /**
     * $doc defaults to an empty MemberDoc rather than to null so that every call site can apply it
     * unconditionally. A member with no prose then adds no keys instead of forcing a null check into the one
     * place that assembles the schema.
     */
    public function __construct(
        public ?string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public MemberDoc $doc = new MemberDoc,
    ) {}

    public static function fromParameter(ReflectionParameter $parameter, MemberDoc $doc = new MemberDoc): self
    {
        $type = $parameter->getType();

        return new self(
            type: $type instanceof ReflectionNamedType ? $type->getName() : null,
            nullable: $type?->allowsNull() ?? true,
            hasDefault: $parameter->isDefaultValueAvailable(),
            default: $parameter->isDefaultValueAvailable() ? self::scalar($parameter->getDefaultValue()) : null,
            doc: $doc,
        );
    }

    /** A member the constructor does not declare: validated on input, but untyped as far as this generator knows. */
    public static function unknown(MemberDoc $doc = new MemberDoc): self
    {
        return new self(null, true, false, null, $doc);
    }

    public function required(): bool
    {
        return ! $this->hasDefault && ! $this->nullable && $this->type !== null;
    }

    /**
     * Defaults are copied into the document only when they are JSON values. An object/enum default (a
     * `new Money(0)` promoted default, say) has no JSON spelling that a client could send back, and emitting
     * a serialised approximation of one would be a `default` the server never actually applies.
     */
    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            is_scalar($value), $value === null => $value,
            is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value => $value,
            default => null,
        };
    }
}
