<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

/**
 * Bean Validation's `message` element, on a Firefly constraint attribute.
 *
 * Every Jakarta constraint carries a `message` — `@NotBlank(message = "give us a name")` — with a default
 * that describes the CONSTRAINT rather than the field (`must not be blank`), and that sentence is what a
 * Spring FieldError publishes. Laravel's validator, by contrast, writes sentences ABOUT the attribute
 * (`The ship to.street field is required.`), humanising a dotted path into prose a client never wrote.
 * This interface is how a constraint states its own sentence: the developer's `message:` element when
 * given, otherwise the constraint's default, interpolated (`{min}`, `{max}`, `{value}`, `{regexp}`, …)
 * at COMPILE time so the manifest carries a finished sentence and the request path formats nothing.
 *
 * Optional on purpose — the NullAware/Compilable idiom rather than a new method on Constraint — so a
 * third-party constraint attribute keeps compiling. One that does not implement it has no sentence of its
 * own: its failures keep Laravel's sentence for the rule, and are still attributed to it by name.
 */
interface HasMessage
{
    /**
     * The sentence published when this constraint fails, or null when the constraint has none of its own
     * (#[Rules] without a `message:`; an unbounded #[Size], which contributes no rule either).
     */
    public function message(): ?string;

    /**
     * Whether message() is the developer's own `message:` element rather than the constraint's default. The
     * developer's sentence wins in EVERY message style — `firefly.validation.messages: laravel` restores
     * Laravel's defaults, not the developer's words — so the descriptor has to know which it is holding.
     */
    public function hasCustomMessage(): bool;
}
