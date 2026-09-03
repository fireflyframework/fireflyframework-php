<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

/**
 * One property's finished JSON Schema plus the one fact that does NOT live inside it: whether the property is
 * required. JSON Schema states requiredness on the PARENT object (`required: [...]`), never on the member, so
 * a mapper that returned only a schema would have thrown away half of what #[NotNull]/#[NotBlank] say.
 */
final readonly class PropertySchema
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public array $schema,
        public bool $required,
    ) {}
}
