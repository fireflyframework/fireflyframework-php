<?php

declare(strict_types=1);

namespace Firefly\Security\OpenApi;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\OpenApi\Security\SecurityRequirement;
use Firefly\OpenApi\Security\SecurityRequirementContributor;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Web\Route\RouteDescriptor;

/**
 * The second half of "a path that is protected says so": a controller action carrying #[PreAuthorize],
 * #[Secured], #[RolesAllowed] or #[PostAuthorize] is refused by the DISPATCHER, not by a URL rule, so
 * `firefly.security.http` may be empty and the operation still be protected. firefly/openapi cannot see the
 * compiled SecurityMethodManifest — that IS a code edge deptrac forbids in that direction — so this package
 * looks up the route's own `controllerClass::methodName` in the manifest it already owns and answers
 * openapi's port.
 *
 * WHY `firefly.security.method.enabled` IS DELIBERATELY NOT READ HERE, although it is the flag named
 * "method security". It stands down the PROXY LINK alone — MethodSecurityInterceptor, the advice
 * #[Transactional]'s engine applies to a #[Service]/#[Component]/#[Repository] — and nothing else:
 * SecurityWiringPass installs MethodSecurityControllerGuard unconditionally once past the master flag, that
 * guard consults only the manifest, and skeleton/config/firefly.php says so in as many words ("Turning this
 * off keeps the controller dispatcher and the CQRS bus enforcing their rules, makes the proxy link a
 * pass-through"). A contributor that fell silent on that flag would publish an operation whose
 * #[PreAuthorize] the dispatcher still answers 401/403 to with NO `security` member at all — OpenAPI's
 * positive claim that no authentication is required, which is the fail-OPEN documentation this whole seam
 * exists to rule out. The master flag IS read, because it really does stand the dispatcher's guard down.
 *
 * `permitAll()` is the pre expression the scanner compiles for a method whose only rules are post/filter
 * ones, so a rule of exactly that value AND NO #[PostAuthorize] means "nothing is required, before or after
 * the call" and this contributor has NO OPINION about it (null) rather than declaring the path public — the
 * URL rules may still protect it, and a contributor that claimed otherwise would publish `security: []` over
 * a guarded path. A #[PostAuthorize] beside it is a different matter: MethodSecurityEvaluator::after()
 * throws through the same deny() the pre rule does — a 401 for an anonymous caller — so a method whose only
 * rule is `#[PostAuthorize("hasRole('ADMIN')")]` is refused at runtime and is published as protected here.
 * #[PreFilter]/#[PostFilter] are deliberately NOT part of that test: a filter NARROWS a result, it never
 * refuses the caller, so an operation whose only rule is one of them really is reachable by anyone.
 *
 * The same three-valued care applies to a route the manifest has never heard of, and to a deployment whose
 * inbound credential OpenAPI has no scheme for.
 *
 * IT NAMES EVERY CONFIGURED SCHEME, NOT A "PRIMARY" ONE, for the reason ConfiguredSecurity gives at
 * length: an operation's `security` array is an OR-list, an application with `http_basic.enabled` beside
 * `jwt.enabled` really does accept either credential, and there is nothing true to pick a winner by. The
 * names are the ones ConfiguredSecurity publishes, plus the authorization server's — the document has to
 * name the credential a caller will actually be challenged for. Where a path is covered by BOTH a URL rule
 * and a method rule, SecurityModel merges the two contributors' entries PER SCHEME NAME, unioning their
 * scope lists: the two mechanisms are conjunctive at runtime (a caller passes the filter AND the
 * dispatcher), so the stricter statement is the true one and the scheme is named once.
 *
 * WHY THE LIST IS REBUILT HERE RATHER THAN READ FROM firefly/openapi: the scheme names are the only thing
 * shared, `ConfiguredSecurity::configuredSchemes()` is private, and reaching for a contributor this package
 * did not publish would need exactly the cross-contributor knowledge SecurityModel exists to hold. The price
 * is four config reads that must keep agreeing with that class's — and, for the fourth name, with
 * firefly/security-oauth2-server's scheme contributor. What catches a disagreement is a test that can see
 * both halves at once: packages/security/tests/OpenApi/MethodSecuredDocumentCapstoneTest.php generates the
 * real document with this package's rules on it, and
 * packages/security-oauth2-server/tests/OpenApi/AuthorizationServerDocumentCapstoneTest.php does the same
 * with the authorization server beside it — each asserting the "no orphan in either direction" invariant.
 * The openapi capstone cannot: it boots neither a method-secured route nor that package.
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
     * `hasScope('orders.read')` — the ONE expression whose authority is an OAuth2 scope AND whose reading in
     * a Security Requirement Object is the same reading the evaluator gives it. The literal is single- or
     * double-quoted, exactly as SecurityExpressionEvaluator parses it.
     */
    private const string SCOPE_CALL = "/hasScope\(\s*(['\"])([^'\"]*)\\1\s*\)/";

    /**
     * What makes an expression's scope demand UNPUBLISHABLE: any disjunction (`or`, `||`) or negation
     * (`not`, `!`) — the three operators SecurityExpressionEvaluator's grammar has beyond `and` — and
     * `hasAnyScope(`, which is a disjunction spelled as one call. See scopesOf().
     */
    private const string NOT_A_CONJUNCTION = '/\b(?:or|not)\b|\|\||!|hasAnyScope\(/i';

    /**
     * Every quoted literal in an expression, blanked before the operators above are looked for, so a scope
     * NAMED `read.or.write` — or a message containing the word "not" — is not read as a boolean operator.
     */
    private const string LITERAL = "/'[^']*'|\"[^\"]*\"/";

    /**
     * The prefix an OAuth2 scope wears as a GRANTED AUTHORITY, which an expression may spell and a document
     * may not. See scopeName().
     */
    private const string SCOPE_AUTHORITY = 'SCOPE_';

    public function __construct(
        private readonly SecurityMethodManifest $manifest,
        private readonly Config $config,
    ) {}

    /**
     * @return list<SecurityRequirement>|null
     */
    public function requirementsFor(RouteDescriptor $route): ?array
    {
        if (! $this->config->bool('firefly.security.enabled', false)) {
            return null;
        }

        $rule = $this->manifest->ruleFor($route->controllerClass, $route->methodName);
        if ($rule === null || ! $this->refuses($rule)) {
            return null;
        }

        $schemes = $this->configuredSchemes();
        if ($schemes === []) {
            return null;
        }

        $scopes = $this->scopesOf($rule);

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
     * Whether this rule can REFUSE a caller, which is the only thing an operation's `security` member can
     * describe. A pre expression of anything but `permitAll()` can; a #[PostAuthorize] can, through the same
     * deny() and with the same 401/403; a #[PreFilter]/#[PostFilter] cannot — it narrows what comes back and
     * hands an anonymous caller an empty list rather than a refusal.
     */
    private function refuses(SecurityMethodDescriptor $rule): bool
    {
        return $rule->expression !== self::NO_PRE_RULE || $rule->postExpression !== null;
    }

    /**
     * The schemes `firefly.security.*` configures, in the order an operation's OR-list names them: the
     * authorization server's own `authorizationCode` flow first, because it is the only entry a Swagger UI
     * "Authorize" button can complete end to end, then the bearer schemes, then HTTP Basic. This is NOT a
     * precedence — the entries are alternatives, any one of which gets a caller in.
     *
     * `oauth2AuthorizationCode` is named from `firefly.security.oauth2.server.enabled` because that is the
     * SAME fact AuthorizationServerSchemeContributor publishes the scheme from: an enabled authorization
     * server has an authorization URL and a token URL whatever its client registry currently holds, so the
     * name this class writes and the entry that package puts in `components.securitySchemes` cannot come
     * apart for a document generated in CI against an empty client table.
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
     * The scopes a rule demands of EVERY caller, which is the one thing an OpenAPI requirement can carry
     * beyond a scheme name. Both halves of the rule are read — a `hasScope()` written on #[PostAuthorize] is
     * as enforced as one written on #[PreAuthorize] — and each is read on its own, because the two are
     * conjunctive with each other: passing the pre rule and then failing the post one is still a refusal.
     *
     * A SECURITY REQUIREMENT OBJECT'S SCOPE LIST IS CONJUNCTIVE: a caller must hold ALL of the names in it.
     * That is why only `hasScope()` inside an expression with no `or`, no `||`, no `not`, no `!` and no
     * `hasAnyScope()` contributes anything. `hasAnyScope('a', 'b')` accepts EITHER, so publishing
     * `['a', 'b']` would send a generated client to the authorization server asking for a scope its
     * registration may not include — and the authorization request is refused, over a document that was
     * stricter than the server. The same is true of `hasScope('a') or hasScope('b')`, which is why the test
     * is on the whole expression rather than on the call.
     *
     * Everything else — hasRole, hasAuthority, a permission check, and now a disjunction of scopes — is an
     * authority the token carries that this document cannot name precisely, so the requirement is published
     * BARE: the scheme alone, which is the honest "you need this credential, and the 403 an under-privileged
     * caller gets is a runtime fact no `security` array was ever able to express". Under-stating a scope
     * list costs a generated client nothing it cannot recover from; over-stating one costs it the
     * authorization request.
     *
     * @return list<string>
     */
    private function scopesOf(SecurityMethodDescriptor $rule): array
    {
        $scopes = [
            ...$this->conjunctiveScopes($rule->expression),
            ...$this->conjunctiveScopes($rule->postExpression ?? ''),
        ];

        return array_values(array_unique($scopes));
    }

    /**
     * @return list<string>
     */
    private function conjunctiveScopes(string $expression): array
    {
        $operators = (string) preg_replace(self::LITERAL, "''", $expression);

        if (preg_match(self::NOT_A_CONJUNCTION, $operators) === 1) {
            return [];
        }

        $found = preg_match_all(self::SCOPE_CALL, $expression, $matches);

        if ($found === false || $found === 0) {
            return [];
        }

        $scopes = [];
        foreach ($matches[2] as $scope) {
            $scope = self::scopeName($scope);

            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * The OAUTH2 SCOPE a captured `hasScope()` literal names, which is not always the literal itself.
     *
     * SecurityExpressionRoot::hasScope() normalises a bare scope to the `SCOPE_x` AUTHORITY a bearer token or
     * an OAuth2 login grants, exactly as hasRole() normalises `ADMIN` to `ROLE_ADMIN`, and its own docblock
     * documents the prefixed spelling — so `hasScope('SCOPE_orders.read')` and `hasScope('orders.read')` are
     * the SAME enforced rule and the evaluator cannot tell them apart. A Security Requirement Object's scope
     * list is not an authority list: it is what a generated client asks the authorization server for, and no
     * client registration holds a scope called `SCOPE_orders.read`. Publishing the prefixed spelling
     * verbatim sends Swagger UI's Authorize dialog after a scope that does not exist, and with the
     * authorization server beside it SecurityModel unions that name into the authorizationCode flow's scopes
     * map, so `components.securitySchemes` declares it too and the authorization request is refused — the
     * exact over-statement scopesOf() rules out for hasAnyScope(), arriving by a different door.
     *
     * The prefix is therefore stripped here, the one place the captured literal becomes a published name. An
     * empty scope — `hasScope('')`, or a bare `hasScope('SCOPE_')` whose remainder is nothing — names no
     * scope at all and is dropped rather than published as `''`.
     *
     * ConfiguredSecurity carries the same rule for a `hasScope:` URL rule, spelled there rather than shared:
     * packages/openapi cannot see this package (deptrac forbids that edge, as the class docblock explains),
     * and what keeps the two agreeing is a test that sees both halves — the capstone suites named there.
     */
    private static function scopeName(string $literal): ?string
    {
        $scope = str_starts_with($literal, self::SCOPE_AUTHORITY)
            ? substr($literal, strlen(self::SCOPE_AUTHORITY))
            : $literal;

        return $scope === '' ? null : $scope;
    }
}
