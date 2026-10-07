<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use RuntimeException;

/** A JSON Logic rule failed while it ran (division by zero, an infinite index, a failing operator): GENERAL. */
class JsonLogicError extends RuntimeException {}
