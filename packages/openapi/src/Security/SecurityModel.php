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
 */
final class SecurityModel
{
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

        $schemes = [];
        foreach ($this->schemeContributors as $contributor) {
            foreach ($contributor->schemes() as $scheme) {
                $schemes[$scheme->name] ??= $scheme->definition;
            }
        }
        ksort($schemes);

        return $schemes;
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
                $entries[] = $requirement->toArray();
            }
        }

        return array_values(array_unique($entries, SORT_REGULAR));
    }
}
