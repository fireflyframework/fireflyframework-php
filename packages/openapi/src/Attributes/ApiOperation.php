<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Attributes;

use Attribute;

/**
 * Overrides what the generator derived for one operation — springdoc's @Operation.
 *
 * PRECEDENCE, which is the whole point of this class: attribute beats docblock beats derived default. Every
 * member here is optional and an omitted one falls THROUGH rather than blanking the derived value, so
 * #[ApiOperation(operationId: 'cancelOrder')] fixes the id and leaves the docblock-derived summary and
 * description exactly where they were. That fall-through is why `summary`/`description` default to the empty
 * string rather than to null: an empty string is what an author writes when they mean "nothing to say here",
 * and it is indistinguishable from "not stated" — so neither can blank a docblock, and an author who wants a
 * genuinely empty summary should delete the docblock instead.
 *
 * `deprecated` is a ?bool for the opposite reason. It has a real third state: `null` means "not stated, use
 * the docblock's @deprecated", `true` means deprecated, and `false` means deprecated: false EVEN IF the
 * docblock says @deprecated — which is the one case where a PHP-level deprecation (the method is going away,
 * internally) genuinely differs from an HTTP-level one (the endpoint is going away, for clients).
 *
 * `operationId` is the member worth the most care. It is REQUIRED to be unique across the whole document and
 * a duplicate is the single flaw that makes most client generators abort rather than degrade, so an id set
 * here is still run through the generator's uniqueness check and suffixed if it collides — the attribute
 * chooses the name, it does not get to break the document. It is also the name a generated client's method
 * ends up with, which is why it is worth setting by hand for anything a human will call often.
 *
 * `tags` REPLACES the derived single tag rather than adding to it, because the derived tag is a guess from
 * the class name and merging a guess with an author's list produces a group nobody asked for. Listing the
 * derived tag alongside a new one is a two-word edit if that is what was wanted.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
{
    /**
     * @param  list<string>  $tags  replaces the controller-derived tag; empty means "leave it alone"
     */
    public function __construct(
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly ?string $operationId = null,
        public readonly ?bool $deprecated = null,
        public readonly array $tags = [],
    ) {}
}
