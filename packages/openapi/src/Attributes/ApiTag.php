<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Attributes;

use Attribute;

/**
 * Names and describes the TAG a controller's operations are grouped under — springdoc's @Tag.
 *
 * Without it the tag name is derived from the class (`Lumen\Web\WalletController` reads as "Wallet") and its
 * description comes from the class docblock. That derivation is good enough most of the time and is exactly
 * why it stays the default; this attribute exists for the cases where it is not. A `V2OrdersController`
 * derives the tag "V2Orders", which is how nobody writes it in prose, and two controllers that split one
 * cohesive area (`OrderController` + `OrderRefundController`) derive two tags where the reader wants one —
 * pointing both at #[ApiTag('Orders')] merges them, because a tag is a NAME, not a class.
 *
 * `description` is optional and falls through to the class docblock when omitted, so a controller that
 * already documents itself in prose only needs the attribute to fix the NAME. The description surfaces in
 * the document's root `tags` array, which is the only place OpenAPI lets a tag carry one — an operation's
 * own `tags` member is a bare list of strings.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiTag
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
    ) {}
}
