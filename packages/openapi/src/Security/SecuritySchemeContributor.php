<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

/**
 * A source of `components.securitySchemes` entries. The shipped one (ConfiguredSecurity) reads
 * `firefly.security.*` through the Config port and needs no code edge to firefly/security at all. The
 * interface exists so that a package holding facts configuration cannot express can add them — an
 * authorization server, whose `authorizationCode` flow URLs live in its own settings and whose scopes live
 * in its registered clients, is the case this seam was cut for, and firefly/security-oauth2-server's
 * AuthorizationServerSchemeContributor is now that second implementation.
 *
 * A SCHEME MUST BE PUBLISHED ON THE SAME FACT A REQUIREMENT IS NAMED ON. A requirement naming a scheme no
 * contributor published is left exactly as written (SecurityModel::resolve()), so it becomes a dangling
 * reference in an invalid document — which is why the authorization server publishes its flow whenever the
 * server is enabled rather than whenever its client registry happens to be populated.
 *
 * Contributors are COLLECTED, not chosen: every bean implementing this adds its schemes, later names never
 * silently replacing earlier ones (SecurityModel keeps the first writer and sorts by name, so the document
 * is deterministic whatever order the container hands them over in).
 *
 * REGISTER AN IMPLEMENTATION AS A #[Component], NOT AS A #[Bean] — and this is load-bearing, not a style
 * preference. The collection is `Container::getAll(self::class)`, which resolves the container tag
 * `firefly.contract.<interface>`, and that tag is written in exactly one place: ContainerRegistrar's
 * interface wiring, which walks the SCANNED ComponentManifest. An object a #[Bean] factory returns is bound
 * under its return type and never tagged, so a contributor registered that way is constructed and then
 * silently dropped — the document would simply lack its schemes, with every test still green.
 * OpenApiAutoConfiguration::securityModel() does fall back to resolving this INTERFACE as a bean name when
 * one is bound under it, which rescues a `#[Bean] public function x(): SecuritySchemeContributor`, but not a
 * bean declaring the concrete class as its return type. Gate the #[Component] on the class instead — it
 * takes #[ConditionalOnClass] and #[ConditionalOnProperty] exactly as HttpSecurityFilter does, so the cost
 * when firefly/openapi is absent or the feature is off is the same nothing a conditional #[Bean] costs.
 */
interface SecuritySchemeContributor
{
    /** @return list<SecurityScheme> */
    public function schemes(): array;
}
