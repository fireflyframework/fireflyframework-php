<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Attributes;

use Attribute;

/**
 * Enriches ONE already-bound parameter — springdoc's @Parameter. Repeatable, and declared on the METHOD
 * rather than on the parameter itself.
 *
 * WHY ON THE METHOD. A controller parameter already carries #[PathVariable]/#[QueryParam]/#[RequestHeader],
 * and those are read by RouteScanner at `firefly:cache` time and compiled into the binding plan the
 * dispatcher runs on. Adding a documentation-only attribute into that same parameter list would put a
 * generator concern inside the hot signature the scanner walks, and would invite the next reader to assume
 * the dispatcher honours it. Keeping it on the method draws the line where it belongs: the binding plan says
 * what the server READS, this says what the document SHOWS.
 *
 * `name` is matched against the WIRE key, not the PHP variable — `X-Tenant`, not `$tenant` — because that is
 * what appears in the document and what a client actually sends. A name matching no binding is IGNORED rather
 * than added as a new parameter: the binding plan is the only honest statement of what the endpoint reads,
 * and inventing a parameter the dispatcher will never look at documents an API that does not exist.
 *
 * `required` is a ?bool so that "not stated" survives; it is honoured for query and header parameters only.
 * A PATH parameter is required by the OpenAPI specification itself — `required: false` is invalid there — so
 * an override is dropped rather than allowed to produce a document a strict validator rejects.
 *
 * `example` is emitted as the Parameter Object's own `example` member, which is still current in 3.1. (The
 * SCHEMA Object's `example` is the one 3.1 deprecated in favour of JSON Schema's `examples` array — see
 * ApiProperty, which is on the schema side of that line and spells it the other way.) A null example is read
 * as "no example": a parameter whose only documented value is null teaches a reader nothing.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiParameter
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly mixed $example = null,
        public readonly ?bool $required = null,
    ) {}
}
