<?php

declare(strict_types=1);

namespace Firefly\Web;

use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The discovered web provider. Placeholder in Task 7 (contributes no passes, binds nothing) so the package
 * is green and discoverable; Task 17 adds register()-time port bindings + the RouteWiringPass /
 * FilterChainRegistrar passes.
 */
final class WebServiceProvider extends FireflyServiceProvider {}
