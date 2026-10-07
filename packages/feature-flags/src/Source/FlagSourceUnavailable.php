<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Source;

use RuntimeException;

/** A source could not be read (a missing file, an unreachable sync endpoint, a database error). */
final class FlagSourceUnavailable extends RuntimeException {}
