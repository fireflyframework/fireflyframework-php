<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

/**
 * Marks a ValidationRule that MUST still run when the attribute is present with a null value.
 *
 * Jakarta Bean Validation's null contract is unambiguous: `null` is a valid value for every constraint
 * except `@NotNull` (and the constraints that subsume it, `@NotEmpty`/`@NotBlank`) — rejecting null is
 *
 * @NotNull's single job, and every other annotation is expected to short-circuit to "valid" so that an
 * optional field does not need `@Email` spelled as "email-or-null". LaraFly honours that by having the
 * ConstraintScanner prepend Laravel's `nullable` flag to a property's compiled rule list, which makes
 * Illuminate skip every NON-implicit rule when the value is null (Validator::isNotNullIfMarkedAsNullable).
 *
 * That flag is exactly what a null-rejecting rule OBJECT must not be subjected to: rule objects are never
 * implicit to Illuminate, so `nullable` would silently disable them and #[NotNull] would stop rejecting
 * anything — the failure mode this marker exists to prevent. Rule-string constraints need no marker, because
 * `required` and its family ARE implicit and keep firing on null regardless of `nullable`.
 *
 * Implement this on a rule reached through the #[Rules] escape hatch when its whole purpose is to have an
 * opinion about null; leave it off otherwise and get Jakarta's skip-on-null behaviour for free.
 */
interface NullAware {}
