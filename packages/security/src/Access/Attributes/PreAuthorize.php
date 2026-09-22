<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Attributes;

use Attribute;

/**
 * Guards a method (or every method of a class) with a SpEL-subset boolean expression (evaluated no-`eval`).
 *
 * `code` and `message` are what the CLIENT reads when the expression refuses. Without them the guard answers
 * `403 ACCESS_DENIED` with the framework's sentence and the authorities the expression named; with them it
 * answers the application's own product code and sentence — `RUN_ROLE_REQUIRED`, "Only a manager may start a
 * run." — so the role rule and the words for breaking it live on the same line, beside the method they
 * guard, instead of in a service the controller has to call before doing anything. One real application kept
 * every attribute at `isAuthenticated()` and judged roles by hand at thirty-five sites, because the only
 * alternative was a refusal that named a PHP class on the wire.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class PreAuthorize
{
    /**
     * @param  ?string  $code  the error code a refusal carries instead of ACCESS_DENIED
     * @param  ?string  $message  the sentence a refusal carries instead of the framework's
     */
    public function __construct(
        public string $expression,
        public ?string $code = null,
        public ?string $message = null,
    ) {}
}
