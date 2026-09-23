<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

use Firefly\Config\Config;
use Firefly\Web\Route\RouteDescriptor;

/**
 * The generator's one view of security: every contributor's schemes merged into the `securitySchemes` map,
 * and every contributor's opinion about a route merged into that operation's `security` array.
 *
 * MERGING IS FIRST-WRITER-WINS AND SORTED, for the reason the generator sorts paths and components: a
 * document that reshuffles itself with container iteration order turns every regeneration into an
 * unreviewable diff, which is what makes teams stop committing the generated file, which is what makes it
 * go stale.
 *
 * REQUIREMENTS ARE AN "OR" LIST, which is what OpenAPI's operation-level `security` means: satisfying ANY
 * entry satisfies the operation. Each entry names a CREDENTIAL, and an application running HTTP Basic
 * beside a bearer filter really does accept either — so a scheme name appears at most ONCE in the list and
 * the contributors that named it have their scope lists UNIONED into that one entry.
 *
 * WHY UNIONED RATHER THAN LISTED TWICE, which is what a plain concatenation did: the mechanisms behind the
 * contributors are conjunctive at runtime. A request passes the URL filter AND the controller dispatcher,
 * so a path whose URL rule needs only a principal while its #[PreAuthorize] needs `hasScope('orders.read')`
 * really needs the scope. Published as two entries — `[{bearerAuth: []}, {bearerAuth: ['orders.read']}]` —
 * the OR reading makes the scopeless one satisfy the operation and the scope requirement means nothing at
 * all. One entry carrying the union is the stricter and truer statement, and it is the only one a generated
 * client can act on.
 *
 * AN EMPTY LIST FROM A CONTRIBUTOR DOES NOT WIN ANY MORE, and that reversal is the same reasoning read
 * forwards. It held while ConfiguredSecurity was the only contributor: the one mechanism letting a request
 * through was the whole truth about the path. It is false as soon as a second mechanism can refuse
 * independently — firefly/security's method-rule contributor is exactly that, and the method-security-first
 * setup it exists to serve (permissive URL rules, #[PreAuthorize] on the handlers, Spring's
 * `anyRequest().permitAll()` shape) is precisely the configuration a permitAll-wins rule would publish as
 * public while the dispatcher answered 401 to every caller. So an empty list contributes NOTHING to the
 * conjunction rather than erasing it, and the operation is published public only when no contributor
 * required anything — which is still the ordinary answer for a path every mechanism opens.
 *
 * That leaves `null` and `[]` with the same effect on the merged list, which is correct rather than
 * redundant: "I have no opinion" and "I require nothing here" are the same contribution to an AND of
 * mechanisms. The distinction stays in the port because it is the difference between a contributor that
 * examined the route and one that never could, and because reading it as "public" is the failure this
 * paragraph exists to prevent.
 *
 * A SCHEME'S DEFAULT SCOPES ARE APPLIED HERE, and this is the only place they are read. A requirement that
 * names a scheme and carries NO scopes of its own inherits `SecurityScheme::$scopes` from the scheme it
 * names — the case the scheme contributor seam was cut for, an authorization server whose registered
 * clients hold the scope list its `authorizationCode` flow hands out, contributed by a package that has no
 * way to reach every route the requirement contributors will be asked about. A requirement that DOES carry
 * scopes keeps them untouched: the operation's own statement is always more specific than the scheme's
 * fallback, and overwriting it is how a document ends up demanding every scope on every path. The two sides
 * are joined here rather than in either contributor because neither can see the other — the scheme list and
 * the requirement list come from different beans, and making a requirement contributor look up a scheme it
 * did not publish would need exactly the cross-contributor knowledge this class exists to hold.
 */
final class SecurityModel
{
    /**
     * The merged schemes, by name, built once. Memoised because requirementsFor() is asked about EVERY route
     * in the manifest and a contributor's schemes() is not free — an authorization server's reads its client
     * registry — while the answer cannot change within one generation: the contributor list is fixed at
     * construction.
     *
     * @var array<string, SecurityScheme>|null
     */
    private ?array $schemeIndex = null;

    /**
     * @param  list<SecuritySchemeContributor>  $schemeContributors
     * @param  list<SecurityRequirementContributor>  $requirementContributors
     */
    public function __construct(
        private readonly array $schemeContributors,
        private readonly array $requirementContributors,
        private readonly Config $config,
    ) {}

    public function enabled(): bool
    {
        return $this->config->bool('firefly.openapi.security.enabled', true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemes(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_map(
            static fn (SecurityScheme $scheme): array => $scheme->definition,
            $this->schemeIndex(),
        );
    }

    /**
     * @return list<array<string, list<string>>>
     */
    public function requirementsFor(RouteDescriptor $route): array
    {
        if (! $this->enabled()) {
            return [];
        }

        // Scheme name => every scope any contributor stated for it, in the order the names were first named.
        /** @var array<string, list<string>> $stated */
        $stated = [];

        foreach ($this->requirementContributors as $contributor) {
            // Silence and "nothing required here" are the same contribution to an AND of mechanisms; neither
            // stands another contributor's requirement down. See the class docblock.
            foreach ($contributor->requirementsFor($route) ?? [] as $requirement) {
                $stated[$requirement->scheme] = array_values(array_unique([
                    ...($stated[$requirement->scheme] ?? []),
                    ...$requirement->scopes,
                ]));
            }
        }

        $entries = [];
        foreach ($stated as $scheme => $scopes) {
            // Resolved AFTER the merge, so a scheme's default scopes fill in only for an entry no
            // contributor gave scopes to — one contributor's specific statement is never topped up with the
            // scheme's catalogue.
            $entries[] = $this->resolve(new SecurityRequirement($scheme, $scopes));
        }

        return $entries;
    }

    /**
     * One requirement as it goes into the document: its own scopes when it has any, and otherwise the
     * default scopes of the scheme it names. A requirement naming a scheme NOBODY contributed is left
     * exactly as written — the document will be invalid, and silently rewriting the entry would hide which
     * contributor produced the dangling name.
     *
     * @return array<string, list<string>>
     */
    private function resolve(SecurityRequirement $requirement): array
    {
        if ($requirement->scopes !== []) {
            return $requirement->toArray();
        }

        $scheme = $this->schemeIndex()[$requirement->scheme] ?? null;

        if ($scheme === null || $scheme->scopes === []) {
            return $requirement->toArray();
        }

        return [$requirement->scheme => $scheme->scopes];
    }

    /**
     * Every contributor's schemes by name, first writer winning and sorted — the single list both schemes()
     * and resolve() read, so the definition the document publishes and the default scopes a requirement
     * inherits always come from the SAME contributor's scheme object. Taking the definition from the first
     * writer and the scopes from a later one would publish a flow whose scopes nothing declares.
     *
     * @return array<string, SecurityScheme>
     */
    private function schemeIndex(): array
    {
        if ($this->schemeIndex !== null) {
            return $this->schemeIndex;
        }

        $index = [];
        foreach ($this->schemeContributors as $contributor) {
            foreach ($contributor->schemes() as $scheme) {
                $index[$scheme->name] ??= $scheme;
            }
        }
        ksort($index);

        return $this->schemeIndex = $index;
    }
}
