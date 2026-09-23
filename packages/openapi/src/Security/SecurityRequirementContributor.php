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
 *   - an empty list — the MECHANISM behind this contributor requires nothing here (a permitAll rule matched
 *     it), which is a claim about that mechanism and not about the operation;
 *   - null — this contributor has NO OPINION (URL authorization is off; the route carries no method rule).
 *
 * Without the third case, a contributor that simply does not know about a route would be indistinguishable
 * from one asserting the route is open, and the document would publish `security: []` — which in OpenAPI
 * means "no authentication required" — for every path some other contributor protects. Silence and "this is
 * public" are different claims and a generated document must not confuse them.
 *
 * WHAT AN EMPTY LIST IS NOT: a veto. The mechanisms these contributors describe are conjunctive at runtime
 * — a request passes the URL filter AND the controller dispatcher — so a permitAll URL rule says nothing
 * about a #[PreAuthorize] on the handler, and SecurityModel publishes the operation as public only when NO
 * contributor required anything. An empty list therefore contributes nothing to the merge, exactly as null
 * does; the difference between them is what the contributor KNOWS, which is worth keeping in the port and
 * worth never reading as "this path is open".
 *
 * REGISTER AN IMPLEMENTATION AS A #[Component], NOT AS A #[Bean], for the reason SecuritySchemeContributor's
 * docblock spells out: the collection is `Container::getAll(self::class)` over the `firefly.contract.*` tag,
 * and only SCANNED components carry that tag. A contributor a #[Bean] factory returns under its own concrete
 * type is built and then silently dropped, and the only symptom is a document that quietly omits its
 * requirements. #[ConditionalOnClass]/#[ConditionalOnProperty] work on a #[Component] (HttpSecurityFilter is
 * the shape to copy), so nothing is lost by scanning it.
 *
 * TWO IMPLEMENTATIONS SHIP. ConfiguredSecurity, here, reads the URL rules through the Config port; and
 * firefly/security's MethodSecurityRequirementContributor reads the compiled method-security rules
 * (#[PreAuthorize], #[Secured], #[RolesAllowed], #[PostAuthorize]) that the controller dispatcher enforces,
 * which no amount of configuration reading could see. The second is the case this interface was cut for:
 * the package that HOLDS the fact answers the port, and this package gains no edge to it.
 */
interface SecurityRequirementContributor
{
    /** @return list<SecurityRequirement>|null */
    public function requirementsFor(RouteDescriptor $route): ?array;
}
