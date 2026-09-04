<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Attributes;

use Attribute;

/**
 * Documents one response the generator cannot derive — springdoc's @ApiResponse. Repeatable.
 *
 * WHAT IS ALREADY DERIVED, and therefore what this is NOT for. The success status comes from #[Mapping]; a
 * `400` is emitted exactly when the binding plan contains something ArgumentResolver can reject before the
 * controller runs; a `422` exactly when some binding carries #[Valid]; and a `default` always, covering every
 * problem the handler itself raises. See OperationFactory::responses(), which explains why each of those is
 * derivable and why guessing more would be worse than saying less.
 *
 * WHAT IT IS FOR is the half that provably cannot be derived: the statuses that depend on the controller's
 * BODY. A `404` from a repository miss, a `409` from a uniqueness clash, a `402` from a payment gateway —
 * none of these is stated anywhere the framework can read, short of parsing the method's statements and
 * resolving every exception type it can reach. `default` already covers them structurally (they all render
 * through the same RFC-9457 ProblemDetailsRenderer), so this attribute is about telling a HUMAN, and a client
 * generator, which of them are real and what they mean.
 *
 * A status declared here that the generator also derived REPLACES the derived entry — that is the documented
 * precedence, and it is what lets an author put real prose on the `200` instead of "Successful response."
 * without losing the derived response's content type.
 *
 * `type` is a PHP type NAME, not a schema: `'array'`, `'string'`, or a DTO class-string, which becomes a
 * `$ref` to a component registered exactly like a request body's. Passing a class the process cannot autoload
 * degrades to an untyped body rather than emitting a dangling pointer.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiResponse
{
    /**
     * @param  int|string  $status  an HTTP status, or the literal string `default`
     * @param  string|null  $type  a PHP type name or DTO class-string; null documents the response as bodiless
     */
    public function __construct(
        public readonly int|string $status,
        public readonly string $description,
        public readonly ?string $type = null,
    ) {}
}
