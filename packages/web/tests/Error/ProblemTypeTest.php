<?php

declare(strict_types=1);

use Firefly\Web\Error\ProblemType;

/**
 * RFC 9457 §3.1.1's `type`, and the three things a deployment can reasonably want from it.
 *
 * The RFC says an absent `type` is IDENTICAL to `about:blank`, which makes emitting it a presentation
 * choice rather than a conformance one — and Spring Boot's ProblemDetail makes that choice by serialising
 * `about:blank` explicitly, so a client reading `type` always finds a string rather than having to know the
 * default. LaraFly follows Spring by default and lets an application go further, because it has something
 * Spring does not: a stable `code` on every error, which is exactly the identifier a problem-type URI wants
 * to be built from.
 */
it('emits about:blank by default, which is what the RFC says an absent type means anyway', function () {
    expect(ProblemType::of('RESOURCE_NOT_FOUND', 'about:blank'))->toBe('about:blank')
        ->and(ProblemType::of('INTERNAL_ERROR', 'about:blank'))->toBe('about:blank');
});

it('omits the member entirely when the deployment asks for the pre-9457 document', function () {
    expect(ProblemType::of('RESOURCE_NOT_FOUND', ''))->toBeNull();
});

it('derives a real type URI from the stable error code when a base is configured', function () {
    expect(ProblemType::of('RESOURCE_NOT_FOUND', 'https://api.example.test/problems'))
        ->toBe('https://api.example.test/problems/resource-not-found')
        // A trailing slash on the base is the obvious thing to write and must not produce a double one.
        ->and(ProblemType::of('ORDER_ALREADY_SHIPPED', 'https://api.example.test/problems/'))
        ->toBe('https://api.example.test/problems/order-already-shipped')
        // The framework's own synthesised codes are URIs like any other.
        ->and(ProblemType::of('HTTP_418', 'https://example.test/p'))->toBe('https://example.test/p/http-418');
});

it('slugs a code the way a URL path wants it, and never emits an empty last segment', function () {
    expect(ProblemType::slug('METHOD_NOT_ALLOWED'))->toBe('method-not-allowed')
        ->and(ProblemType::slug('EDITION_LIMIT'))->toBe('edition-limit')
        ->and(ProblemType::slug('X'))->toBe('x')
        // A code that slugs to nothing would make the base URI itself the type of every such problem, which
        // is worse than saying nothing: the base is the collection, not a member of it.
        ->and(ProblemType::of('', 'https://api.example.test/problems'))->toBe('about:blank')
        ->and(ProblemType::of('___', 'https://api.example.test/problems'))->toBe('about:blank');
});
