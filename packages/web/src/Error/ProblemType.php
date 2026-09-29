<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

/**
 * RFC 9457 §3.1.1's `type` member: the URI reference that identifies WHAT KIND of problem this is, as
 * opposed to `instance`, which identifies this one occurrence of it.
 *
 * THE RFC MAKES THIS A PRESENTATION CHOICE, NOT A CONFORMANCE ONE. §3.1.1 says an absent `type` is
 * identical to `about:blank`, so a document with no `type` at all is conformant — which is what LaraFly
 * published for its whole life. Spring Boot's ProblemDetail nevertheless serialises `about:blank`
 * explicitly, and that is the better default for the same reason a nullable column with a default beats one
 * without: a client reading `type` always finds a string, and never has to encode the RFC's equivalence
 * rule to know what its absence meant.
 *
 * AND LARAFLY HAS SOMETHING SPRING DOES NOT: a stable `code` on every single error, which is exactly what a
 * problem-type URI wants to be built from. Point `firefly.web.problem.type-uri` at a base and every document
 * carries a dereferenceable type — `https://api.example.test/problems/resource-not-found` — that a client
 * can switch on and a person can open, derived from the same identifier the log line and the support ticket
 * already quote. One key, three behaviours, each of them a deliberate position rather than a default nobody
 * chose.
 */
final class ProblemType
{
    /** What the key holds when the document should carry the RFC's own "no specific type" value. */
    public const string BLANK = 'about:blank';

    /**
     * The `type` member for this error code, or null when it is to be omitted.
     *
     * A code that slugs to nothing falls back to `about:blank` rather than to the bare base URI: the base
     * names the COLLECTION of problem types, and handing it out as the type of an unnameable problem would
     * make every such problem look like the same one.
     */
    public static function of(string $code, string $typeUri): ?string
    {
        if ($typeUri === '') {
            return null;
        }

        if ($typeUri === self::BLANK) {
            return self::BLANK;
        }

        $slug = self::slug($code);

        return in_array($slug, ['', '.', '..'], true)
            ? self::BLANK
            : rtrim($typeUri, '/').'/'.rawurlencode($slug);
    }

    /** `RESOURCE_NOT_FOUND` → `resource-not-found`: the stable code as a URL path segment. */
    public static function slug(string $code): string
    {
        return trim(strtolower(str_replace('_', '-', $code)), '-');
    }
}
