<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

/**
 * Binds a route segment to a handler parameter — and, when `pattern` is given, states the SHAPE the segment
 * must have before the handler runs.
 *
 * THE DEFECT `pattern` CLOSES. This attribute used to be a binding marker only. `GET /rooms/not-a-uuid`
 * therefore reached the controller, reached the repository, reached PostgreSQL as `where id = ?::uuid`, and
 * came back as `500 INTERNAL_ERROR` with a correlation id — for a request that was simply wrong, on every
 * `{id}` route of one real application, where three controllers remembered to check the shape and thirty did
 * not. A rule that depends on every future controller remembering is not a rule; the shape belongs beside
 * the parameter that has it, and ArgumentResolver enforces it before anything else runs.
 *
 * A MISS IS A 404, NOT A 400. It answers `ResourceNotFoundException` with `notFoundCode` (default
 * `RESOURCE_NOT_FOUND`) and `notFoundMessage` (default a sentence derived from the parameter name: `roomId`
 * → "That room does not exist."), so the wire cannot tell "no such room" from "not even a room id". Under
 * row-level security those ARE the same answer, and a distinct code would let a caller learn which ids are
 * well-formed. Give the attribute the same code and sentence your repository answers for a missing row, and
 * the two 404s are byte-for-byte identical.
 *
 * `pattern` is a PCRE body without delimiters; the resolver anchors it to the whole segment (`^(?:…)$`) and
 * matches case-insensitively, and RouteScanner refuses an invalid one at cache time rather than letting
 * preg_match() fail on every request. firefly/openapi publishes it as the parameter's JSON Schema `pattern`.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class PathVariable
{
    /** RFC 4122 text form, any version — what PostgreSQL's `uuid` type accepts and nothing more. */
    public const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * @param  ?string  $name  the route parameter name (defaults to the method parameter name)
     * @param  ?string  $pattern  a PCRE body the whole segment must match; null accepts any segment
     * @param  ?string  $notFoundCode  the error code a mismatch answers with (default RESOURCE_NOT_FOUND)
     * @param  ?string  $notFoundMessage  the sentence a mismatch answers with (default derived from the name)
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $pattern = null,
        public readonly ?string $notFoundCode = null,
        public readonly ?string $notFoundMessage = null,
    ) {}
}
