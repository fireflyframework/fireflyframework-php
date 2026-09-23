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
 *
 * REGISTER AN IMPLEMENTATION AS A #[Component], NOT AS A #[Bean], for the reason SecuritySchemeContributor's
 * docblock spells out: the collection is `Container::getAll(self::class)` over the `firefly.contract.*` tag,
 * and only SCANNED components carry that tag. A contributor a #[Bean] factory returns under its own concrete
 * type is built and then silently dropped, and the only symptom is a document that quietly omits its
 * requirements. #[ConditionalOnClass]/#[ConditionalOnProperty] work on a #[Component] (HttpSecurityFilter is
 * the shape to copy), so nothing is lost by scanning it.
 *
 * The shipped implementation is ConfiguredSecurity, which reads the URL rules. Nothing reads method-security
 * attributes (#[PreAuthorize], #[Secured]) into a requirement today — a contributor doing that is what this
 * interface is here to accept, not something the framework already does.
 */
interface SecurityRequirementContributor
{
    /** @return list<SecurityRequirement>|null */
    public function requirementsFor(RouteDescriptor $route): ?array;
}
