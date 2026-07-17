<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Attribute;

/**
 * INERT metadata marker, declared now so the type exists. Reading #[Valid] on a controller parameter to
 * auto-validate a #[RequestBody] DTO needs the argument-resolution pipeline + AOP boundary, neither of which
 * exists in M5 — that is M6/web territory. In M6 the web dispatcher and the ConstraintScanner read this
 * marker; broadening the target to also allow `TARGET_PROPERTY` enables nested-DTO cascade.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Valid {}
