<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

/**
 * A source of `components.securitySchemes` entries. The shipped one (ConfiguredSecurity) reads
 * `firefly.security.*` through the Config port and needs no code edge to firefly/security at all; a package
 * with facts configuration cannot express — firefly/security-oauth2-server, whose authorizationCode flow
 * URLs come from AuthorizationServerSettings and whose scopes come from the registered clients — implements
 * this instead, under a #[ConditionalOnClass] so it costs nothing when firefly/openapi is absent.
 *
 * Contributors are COLLECTED, not chosen: every bean implementing this adds its schemes, later names never
 * silently replacing earlier ones (SecurityModel keeps the first writer and sorts by name, so the document
 * is deterministic whatever order the container hands them over in).
 */
interface SecuritySchemeContributor
{
    /** @return list<SecurityScheme> */
    public function schemes(): array;
}
