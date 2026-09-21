<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Attribute;

/**
 * Cascade validation into the object (or the objects) this member holds.
 *
 * On a #[RequestBody] controller parameter, the web dispatcher reads it and runs BeanValidator over the raw
 * body before hydrating the DTO. On a DTO member, ConstraintScanner reads it and compiles the member's own
 * constraints under dot-prefixed keys: a class-typed member cascades as `shipTo.postcode`; a member typed
 * `array` or `iterable` cascades into EVERY element as `lines.*.sku`, which Illuminate reports per element
 * and the field error spells `lines[0].sku`.
 *
 * `each` is the element class of a list member — Bean Validation's `List<@Valid Line>`, spelled the way PHP
 * can spell it — for a member whose docblock does not say. Without it the scanner reads `@var list<Line>`
 * on the member or `@param list<Line> $lines` on the constructor (the same tag RouteScanner hydrates from);
 * with neither, a #[Valid] list is refused at scan time rather than silently skipped. It is ignored on a
 * class-typed member, whose element is its declared type.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Valid
{
    /**
     * @param  string|null  $each  the fully-qualified class every element of a list-typed member is validated
     *                             as (a plain string rather than class-string on purpose: a name that does not
     *                             exist is refused by the scanner with a message, not by static analysis)
     */
    public function __construct(public readonly ?string $each = null) {}
}
