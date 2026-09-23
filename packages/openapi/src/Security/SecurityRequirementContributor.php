<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

use Firefly\Web\Route\RouteDescriptor;

/**
 * A source of operation-level `security` requirements for ONE route.
 *
 * The return value is three-valued on purpose, and the third value is the whole reason this is not just
 * `array`:
 *
 *   - a non-empty list — this contributor says the operation needs these requirements;
 *   - an empty list — this contributor says the operation is PUBLIC (a permitAll rule matched it);
 *   - null — this contributor has NO OPINION (URL authorization is off; the route carries no method rule).
 *
 * Without the third case, a contributor that simply does not know about a route would be indistinguishable
 * from one asserting the route is open, and the document would publish `security: []` — which in OpenAPI
 * means "no authentication required" — for every path some other contributor protects. Silence and "this is
 * public" are different claims and a generated document must not confuse them.
 */
interface SecurityRequirementContributor
{
    /** @return list<SecurityRequirement>|null */
    public function requirementsFor(RouteDescriptor $route): ?array;
}
