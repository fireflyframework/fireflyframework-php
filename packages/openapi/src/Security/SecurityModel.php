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
 * entry satisfies the operation. Two contributors both requiring something therefore produce two entries,
 * and a caller holding either gets in — the correct reading when a path is covered by both a URL rule and a
 * method rule naming the same scheme, since one credential satisfies both. An EMPTY list from a contributor
 * (`permitAll`) wins over everything: a path the framework lets through unauthenticated is public whatever
 * anyone else believes, because that is what will actually happen at runtime.
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

        $entries = [];
        foreach ($this->requirementContributors as $contributor) {
            $requirements = $contributor->requirementsFor($route);

            if ($requirements === null) {
                continue;
            }

            if ($requirements === []) {
                return []; // a permitAll path is public, whatever anyone else thinks
            }

            foreach ($requirements as $requirement) {
                $entries[] = $this->resolve($requirement);
            }
        }

        return array_values(array_unique($entries, SORT_REGULAR));
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
