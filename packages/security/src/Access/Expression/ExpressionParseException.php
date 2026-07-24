<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Expression;

use RuntimeException;

/** A security expression was malformed or referenced a non-whitelisted function — the evaluator denies. */
final class ExpressionParseException extends RuntimeException {}
