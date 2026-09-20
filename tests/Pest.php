<?php

declare(strict_types=1);

use Firefly\Testing\Pest\FireflyExpectations;

// Root Pest bootstrap. Package test suites live in packages/*/tests and are
// discovered via phpunit.xml.dist. Shared expectations/helpers go here.

FireflyExpectations::register();

// Browser tests: everything under tests/Browser drives a real Chromium through
// pestphp/pest-plugin-browser against the Testbench-booted app (the plugin serves
// it in-process). tests/Browser is its own `browser` testsuite in phpunit.xml.dist
// and is excluded from the default `unit` suite, so the default gate never needs
// Node; `composer test:browser` runs this suite alone. The `browser` group is a
// label only (for --group=browser filtering): the suite split in phpunit.xml.dist
// is what keeps these files out of the default run — Pest includes every file of
// the configured suite before it filters groups, and the plugin boots Playwright
// the moment a file under tests/Browser is included.
pest()->group('browser')->in('Browser');

pest()->browser()
    ->inChrome()
    ->inLightMode()
    ->timeout(10000);
