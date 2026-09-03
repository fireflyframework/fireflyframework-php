<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\OpenApi\Attributes\ApiParameter;
use Firefly\OpenApi\Attributes\ApiResponse;

/**
 * Everything the three documentation sources — #[ApiOperation] and friends, the method docblock, and the
 * derivation from the method name — agreed on for ONE operation, after ApiDocs has merged them.
 *
 * The merge happens once, here, rather than at each use site, because the precedence rule (attribute beats
 * docblock beats derived) is only trustworthy if it is applied in exactly one place. OperationFactory and
 * OpenApiGenerator both need pieces of this — the factory writes the summary and responses, the generator
 * needs the operationId candidate and the tag list before it can dedupe ids and build the root `tags` array
 * — and having each re-run the precedence would be two chances for them to disagree about the same route.
 *
 * `summary` is never empty: it falls back to the humanised method name, which is the one human-authored
 * label every route carries. `description` IS allowed to be empty, and an empty one is omitted from the
 * document entirely — the placeholder it replaced ("Handled by Foo::bar().") was worse than silence.
 */
final readonly class OperationDoc
{
    /**
     * @param  list<string>|null  $tags  null when nothing overrode the controller-derived tag
     * @param  list<ApiResponse>  $responses  extra responses, in declaration order
     * @param  array<string, ApiParameter>  $parameters  keyed by the WIRE name the attribute claimed
     */
    public function __construct(
        public string $summary,
        public string $description,
        public ?string $operationId,
        public bool $deprecated,
        public ?array $tags,
        public array $responses,
        public array $parameters,
    ) {}
}
