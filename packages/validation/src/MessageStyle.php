<?php

declare(strict_types=1);

namespace Firefly\Validation;

/**
 * How a field error's `message` is worded — the `firefly.validation.messages` key.
 *
 * `Constraint` (the default) publishes the CONSTRAINT's own sentence, the way Spring's FieldError does:
 * `must not be blank`, `size must be between 1 and 50`, `must be a well-formed email address` — a statement
 * about the rule, with the field named once, in `field`, exactly as the client spelled it. `Laravel` keeps
 * the sentence Laravel's validator wrote, attribute humanisation included (`The ship to.street field is
 * required.`), for an application whose clients or tests already assert on it. The field path and the
 * `constraint` member are the same in both styles; only the sentence differs.
 */
enum MessageStyle: string
{
    case Constraint = 'constraint';
    case Laravel = 'laravel';
}
