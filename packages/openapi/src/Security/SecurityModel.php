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
 *
 * AND THE SAME JOIN RUNS THE OTHER WAY, for the same reason and over the same two lists: a scope an
 * operation REQUIRES under an `oauth2` scheme is DECLARED by that scheme's flows. Without it the two halves
 * of one fact are stated by two contributors and published by only one — an authorization server whose
 * registry holds no client asking for `orders.read` publishes `scopes: {}` while a `#[PreAuthorize]`
 * elsewhere in the same document puts `orders.read` on the very scheme that flow describes. The cost is not
 * cosmetic: Swagger UI's Authorize dialog offers only the scopes the Flow Object declares, so the
 * "Authorize button that completes the flow" the scheme was published for cannot complete it for exactly
 * those operations, and a strict 3.x linter (Spectral's `oas3-operation-security-defined`) rejects a
 * requirement naming a scope its scheme does not define. So every scope this document states for an
 * `oauth2` scheme is unioned into every flow that scheme declares, sorted, with the scope's own name as its
 * description where the contributor gave none — this class has no vocabulary of its own and inventing prose
 * would put words in the owning package's mouth. A flow that already describes the scope is left exactly as
 * its contributor wrote it. `openIdConnect` is deliberately untouched: its scopes are declared at the
 * discovery document `openIdConnectUrl` points at, not in this one, so there is no map here to join into.
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
     * Every scope this document's operations have stated, by the scheme name they stated it under — the half
     * schemes() joins back into an `oauth2` flow's `scopes` map. A SET rather than a list, because the same
     * scope arrives once per operation that needs it.
     *
     * IT FILLS AS requirementsFor() ANSWERS, which is why schemes() is asked LAST: OpenApiGenerator::build()
     * walks every surviving route into `paths` and only then reads `components.securitySchemes`, so by the
     * time the map is published every operation that will appear in the document has already stated what it
     * needs. That ordering is the generator's, is commented at its call site, and is what the capstones pin
     * end to end. Reading the schemes FIRST is not an error — it answers exactly what the contributors
     * published, which is the right answer for a caller that has asked about no route at all.
     *
     * @var array<string, array<string, true>>
     */
    private array $statedScopes = [];

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
     * The `components.securitySchemes` map as the document publishes it: each contributor's Security Scheme
     * Object, with every scope this document's operations have stated for it declared by its flows. See the
     * class docblock for why the second half is this class's job, and $statedScopes for why the generator
     * asks for this LAST.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemes(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_map(
            fn (SecurityScheme $scheme): array => $this->declared($scheme),
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
            $entry = $this->resolve(new SecurityRequirement($scheme, $scopes));

            // Recorded as RESOLVED, not as stated: what the flows must declare is what the document ends up
            // demanding, inherited default scopes included.
            $this->record($entry);

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Remember one published requirement, so the scheme it names can declare the scopes it asks for.
     *
     * @param  array<string, list<string>>  $entry
     */
    private function record(array $entry): void
    {
        foreach ($entry as $scheme => $scopes) {
            foreach ($scopes as $scope) {
                $this->statedScopes[$scheme][$scope] = true;
            }
        }
    }

    /**
     * One scheme as `components.securitySchemes` publishes it: the contributor's definition, with every
     * scope the document states under this name declared by each of the scheme's flows.
     *
     * The union covers ALL of the scheme's flows because a Security Requirement Object names a SCHEME and
     * never a flow — a caller may hold that scope through whichever of them their client is registered for,
     * so a map that declared it in one and not the others would still leave an operation asking for a scope
     * half the scheme cannot issue. An entry the contributor already wrote is never overwritten: its
     * description is the owning package's sentence (the consent screen's own words, for the authorization
     * server) and this class's fallback is only a name repeated. A flow with nothing to add is returned
     * untouched, byte for byte, rather than rebuilt and re-sorted.
     *
     * @return array<string, mixed>
     */
    private function declared(SecurityScheme $scheme): array
    {
        $definition = $scheme->definition;
        $stated = $this->statedScopes[$scheme->name] ?? [];

        // `openIdConnect` states its scopes at its discovery URL, not in this document; every other type has
        // no scopes map at all. Neither is a place a scope can be declared, so neither is touched.
        if ($stated === [] || ($definition['type'] ?? null) !== 'oauth2' || ! is_array($definition['flows'] ?? null)) {
            return $definition;
        }

        /** @var array<string, mixed> $flows */
        $flows = $definition['flows'];

        foreach ($flows as $name => $flow) {
            if (! is_array($flow)) {
                continue;
            }

            /** @var array<string, mixed> $scopes */
            $scopes = is_array($flow['scopes'] ?? null) ? $flow['scopes'] : [];
            $missing = array_diff_key($stated, $scopes);

            if ($missing === [] && isset($flow['scopes'])) {
                continue;
            }

            foreach (array_keys($missing) as $scope) {
                $scopes[$scope] = $scope;
            }

            // Sorted for the reason every other map in this document is: a `scopes` map that reshuffles with
            // route iteration order turns a regeneration into an unreviewable diff.
            ksort($scopes);
            $flow['scopes'] = $scopes;
            $flows[$name] = $flow;
        }

        $definition['flows'] = $flows;

        return $definition;
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
