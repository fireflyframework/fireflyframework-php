<?php

declare(strict_types=1);

namespace Firefly\Security\OpenApi;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\OpenApi\Security\SecurityRequirement;
use Firefly\OpenApi\Security\SecurityRequirementContributor;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Web\Route\RouteDescriptor;

/**
 * The second half of "a path that is protected says so": a controller action carrying #[PreAuthorize],
 * #[Secured] or #[RolesAllowed] is refused by the DISPATCHER, not by a URL rule, so `firefly.security.http`
 * may be empty and the operation still be protected. firefly/openapi cannot see the compiled
 * SecurityMethodManifest — that IS a code edge deptrac forbids in that direction — so this package looks up
 * the route's own `controllerClass::methodName` in the manifest it already owns and answers openapi's port.
 *
 * `permitAll()` is the pre expression the scanner compiles for a method whose only rules are post/filter
 * ones, so a rule of exactly that value means "nothing is required before the call" and this contributor
 * has NO OPINION about it (null) rather than declaring the path public — the URL rules may still protect it,
 * and a contributor that claimed otherwise would publish `security: []` over a guarded path. The same
 * three-valued care applies to a route the manifest has never heard of, and to a deployment whose inbound
 * credential OpenAPI has no scheme for.
 *
 * IT NAMES EVERY CONFIGURED SCHEME, NOT A "PRIMARY" ONE, for the reason ConfiguredSecurity gives at
 * length: an operation's `security` array is an OR-list, an application with `http_basic.enabled` beside
 * `jwt.enabled` really does accept either credential, and there is nothing true to pick a winner by. The
 * names are the ones ConfiguredSecurity publishes, plus the authorization server's — the document has to
 * name the credential a caller will actually be challenged for, and SecurityModel merges the two
 * contributors' entries into one deduplicated list, so a path covered by BOTH a URL rule and a method rule
 * says the same thing once.
 *
 * WHY THE LIST IS REBUILT HERE RATHER THAN READ FROM firefly/openapi: the scheme names are the only thing
 * shared, `ConfiguredSecurity::configuredSchemes()` is private, and reaching for a contributor this package
 * did not publish would need exactly the cross-contributor knowledge SecurityModel exists to hold. The
 * price is four config reads that must keep agreeing with that class's; the openapi capstone, which asserts
 * that every published scheme is named by something and everything named is published, is where a
 * disagreement shows up.
 *
 * REGISTERED AS A #[Component], NOT AS A #[Bean]: the collection is `Container::getAll()` over the
 * `firefly.contract.*` tag, and only SCANNED components carry that tag — a contributor a #[Bean] factory
 * returns under this concrete type is built and then silently dropped, with a quietly smaller document as
 * the only symptom. The conditions are the HttpSecurityFilter shape, so an application without
 * firefly/openapi (a `suggest` of this package, never a require) never loads a class implementing an
 * absent interface, and one with the master flag off registers nothing.
 */
#[Component]
#[ConditionalOnClass(SecurityRequirementContributor::class)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
final class MethodSecurityRequirementContributor implements SecurityRequirementContributor
{
    /**
     * The pre expression MethodSecurityScanner compiles for a method that declares only post/filter rules —
     * "nothing is required before the call", which is not the same claim as "this path is public".
     */
    private const string NO_PRE_RULE = 'permitAll()';

    /**
     * `hasScope('orders.read')` and `hasAnyScope('a', 'b')` are the two expressions whose authority IS an
     * OAuth2 scope. The literals are single- or double-quoted and comma-separated, exactly as
     * SecurityExpressionEvaluator parses them.
     */
    private const string SCOPE_CALLS = "/has(?:Any)?Scope\(([^)]*)\)/";

    public function __construct(
        private readonly SecurityMethodManifest $manifest,
        private readonly Config $config,
    ) {}

    /**
     * @return list<SecurityRequirement>|null
     */
    public function requirementsFor(RouteDescriptor $route): ?array
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.method.enabled', true)) {
            return null;
        }

        $rule = $this->manifest->ruleFor($route->controllerClass, $route->methodName);
        if ($rule === null || $rule->expression === self::NO_PRE_RULE) {
            return null;
        }

        $schemes = $this->configuredSchemes();
        if ($schemes === []) {
            return null;
        }

        $scopes = $this->scopesOf($rule->expression);

        return array_map(
            // The scope list rides on the TOKEN-shaped schemes only, the rule ConfiguredSecurity applies for
            // the same reason: a scope is something a client asks a token endpoint for, and HTTP Basic has
            // no such vocabulary — naming one beside it would publish a parameter nobody can supply.
            static fn (string $scheme): SecurityRequirement => new SecurityRequirement(
                $scheme,
                $scheme === 'httpBasic' ? [] : $scopes,
            ),
            $schemes,
        );
    }

    /**
     * The schemes `firefly.security.*` configures, in the order an operation's OR-list names them: the
     * authorization server's own `authorizationCode` flow first, because it is the only entry a Swagger UI
     * "Authorize" button can complete end to end, then the bearer schemes, then HTTP Basic. This is NOT a
     * precedence — the entries are alternatives, any one of which gets a caller in.
     *
     * jwt and resource_server are mutually exclusive at boot (SecurityWiringPass refuses the pair), so the
     * list holds at most one plain bearer scheme.
     *
     * @return list<string>
     */
    private function configuredSchemes(): array
    {
        $schemes = [];

        if ($this->config->bool('firefly.security.oauth2.server.enabled', false)) {
            $schemes[] = 'oauth2AuthorizationCode';
        }

        if ($this->config->bool('firefly.security.oauth2.resource_server.enabled', false)) {
            $schemes[] = 'oauth2ResourceServer';
        }

        if ($this->config->bool('firefly.security.jwt.enabled', false)) {
            $schemes[] = 'bearerAuth';
        }

        if ($this->config->bool('firefly.security.http_basic.enabled', false)) {
            $schemes[] = 'httpBasic';
        }

        return $schemes;
    }

    /**
     * The scopes an expression demands, which is the one thing an OpenAPI requirement can carry beyond a
     * scheme name.
     *
     * Only `hasScope()`/`hasAnyScope()` contribute. Everything else — hasRole, hasAuthority, a permission
     * check — is an authority the token carries but the FLOW does not name, and putting it in the `scopes`
     * list would tell a generated client to ask the authorization server for a scope it has never heard of.
     *
     * @return list<string>
     */
    private function scopesOf(string $expression): array
    {
        $found = preg_match_all(self::SCOPE_CALLS, $expression, $matches);

        if ($found === false || $found === 0) {
            return [];
        }

        $scopes = [];
        foreach ($matches[1] as $arguments) {
            foreach (explode(',', $arguments) as $argument) {
                $scope = trim(trim($argument), "'\"");
                if ($scope !== '') {
                    $scopes[] = $scope;
                }
            }
        }

        return array_values(array_unique($scopes));
    }
}
