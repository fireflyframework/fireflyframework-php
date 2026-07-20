<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The discovered resilience provider. Placeholder in Task 1 (contributes no passes, binds nothing) so the
 * package is green and discoverable; Task 7 re-parents it onto AutoConfiguration, pointing the engine at the
 * compiled manifests that describe ResilienceAutoConfiguration.
 */
final class ResilienceServiceProvider extends FireflyServiceProvider {}
