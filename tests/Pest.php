<?php

declare(strict_types=1);
use Firefly\Testing\Pest\FireflyExpectations;

// Root Pest bootstrap. Package test suites live in packages/*/tests and are
// discovered via phpunit.xml.dist. Shared expectations/helpers go here as the
// framework grows. Kept intentionally minimal for the kernel milestone.

FireflyExpectations::register();
