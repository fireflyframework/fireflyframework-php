<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Firefly\OpenApi\Attributes\ApiProperty;
use Firefly\OpenApi\Generator\DocBlock;
use ReflectionAttribute;
use ReflectionParameter;
use ReflectionProperty;

/**
 * The prose half of one DTO member's schema: everything about it that neither the declared PHP type nor the
 * compiled constraint list can say.
 *
 * MemberType is the other half and the split is the same one TypeSchema and ConstraintSchemaMapper already
 * make: each source is asked only about what it actually knows. A type knows `?int`; a rule list knows
 * `gte:1`; a docblock knows "the number of units to reserve, never zero"; only an author writing
 * #[ApiProperty] knows a `format` more precise than the validator enforces or an example worth publishing.
 * None of the four can be derived from the others, so all four are read and merged in a stated order.
 *
 * PRECEDENCE for `description`: #[ApiProperty(description:)] beats the member's OWN docblock beats the
 * constructor's `@param` line for it. That order is the one people expect from reading the file top to
 * bottom — the closer a statement sits to the member, the more specific it is — and the `@param` fallback
 * matters more than it looks, because a promoted constructor property is where most LaraFly DTOs put
 * everything and `@param` is the only place PHPDoc lets you describe one without inventing a property
 * docblock for a parameter.
 *
 * NOTHING IS INVENTED. A member with no description in any of the three sources gets no `description` key at
 * all, rather than a humanised restatement of its own name — `"quantity": {"description": "Quantity"}` is
 * noise that costs a reader a second to dismiss and costs the file a line per property forever.
 */
final readonly class MemberDoc
{
    public function __construct(
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
        public ?string $format = null,
        public bool $deprecated = false,
    ) {}

    /**
     * A constructor parameter, promoted or not.
     *
     * PHP applies an attribute written on a PROMOTED parameter to both the parameter and the property it
     * creates, filtered by that attribute's own targets — so reading the parameter finds #[ApiProperty] in the
     * promoted case, and the property is consulted anyway for the non-promoted-but-separately-declared case
     * and for its docblock, which only ever exists on the property.
     */
    public static function forParameter(ReflectionParameter $parameter, DocBlock $constructor): self
    {
        $property = self::promoted($parameter);

        $attribute = self::attribute($parameter->getAttributes(ApiProperty::class))
            ?? ($property === null ? null : self::attribute($property->getAttributes(ApiProperty::class)));

        return self::merge(
            $attribute,
            $property === null ? DocBlock::empty() : DocBlock::parse($property->getDocComment()),
            $constructor->params()[$parameter->getName()] ?? '',
        );
    }

    /**
     * A member the constructor does not take. ConstraintManifest still carries rules for it — BeanValidator
     * validates the RAW decoded array, so such a member is enforced on input even though nothing hydrates it
     * — and it is documented for exactly that reason, so its prose has to be reachable too.
     */
    public static function forProperty(ReflectionProperty $property): self
    {
        return self::merge(
            self::attribute($property->getAttributes(ApiProperty::class)),
            DocBlock::parse($property->getDocComment()),
            '',
        );
    }

    public function isEmpty(): bool
    {
        return $this->description === null && ! $this->hasExample && $this->format === null && ! $this->deprecated;
    }

    /**
     * Layers this member's prose onto its finished schema.
     *
     * `description` goes FIRST because it is what a human reads first in a rendered file, and because the
     * keys that follow it (`type`, `minimum`, …) are the machine's half. `format` OVERWRITES a
     * constraint-derived one — see ApiProperty for why an author's format is the more precise statement.
     *
     * `examples` is the plural ARRAY form, not `example`. OpenAPI 3.1 aligned the Schema Object with JSON
     * Schema 2020-12, whose keyword is `examples`, and explicitly deprecated the singular `example` inherited
     * from 3.0. Both render in today's viewers; only one of them survives a strict 3.1 validator's
     * deprecation warning, and only one of them is what a 2020-12 tool reads.
     *
     * Safe to call on a `$ref`-valued property, which is why DtoSchemaFactory does. In JSON Schema 2020-12 —
     * and so in OpenAPI 3.1, unlike 3.0 — sibling keywords alongside `$ref` are legal and are simply applied
     * with it. That holds for ANNOTATIONS like these; it does not hold for validation keywords, which is why
     * the nullable-nested-DTO case next door still has to spell itself as an explicit `anyOf`.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function apply(array $schema): array
    {
        if ($this->description !== null) {
            $schema = ['description' => $this->description, ...$schema];
        }

        if ($this->format !== null) {
            $schema['format'] = $this->format;
        }

        if ($this->hasExample) {
            $schema['examples'] = [$this->example];
        }

        if ($this->deprecated) {
            $schema['deprecated'] = true;
        }

        return $schema;
    }

    private static function merge(?ApiProperty $attribute, DocBlock $doc, string $param): self
    {
        $description = self::first($attribute->description ?? '', $doc->prose(), $param);
        $format = trim($attribute->format ?? '');

        return new self(
            description: $description === '' ? null : $description,
            hasExample: $attribute?->example !== null,
            example: $attribute?->example,
            format: $format === '' ? null : $format,
            deprecated: $attribute->deprecated ?? false,
        );
    }

    /**
     * The promoted property a constructor parameter declares, or null when the parameter promotes nothing.
     * Guarded on hasProperty() as well as isPromoted() because a class this process reflects may have been
     * autoloaded from a different build than the manifest was compiled against, and a missing property must
     * degrade to "no docblock" rather than raise.
     */
    private static function promoted(ReflectionParameter $parameter): ?ReflectionProperty
    {
        $class = $parameter->getDeclaringClass();

        if ($class === null || ! $parameter->isPromoted() || ! $class->hasProperty($parameter->getName())) {
            return null;
        }

        return $class->getProperty($parameter->getName());
    }

    /**
     * @param  list<ReflectionAttribute<ApiProperty>>  $attributes
     */
    private static function attribute(array $attributes): ?ApiProperty
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private static function first(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
