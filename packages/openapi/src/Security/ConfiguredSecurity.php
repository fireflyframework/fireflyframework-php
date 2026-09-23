<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

use Firefly\Config\Config;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Support\Str;

/**
 * THE DOCUMENT FINALLY DESCRIBES THE SERVER'S FRONT DOOR.
 *
 * For two releases this package emitted no `securitySchemes` and no operation `security`, and the reason
 * given in the module doc was a code edge `deptrac.yaml` does not permit. That reason was half right: the
 * edge is indeed forbidden, and it is also unnecessary for nearly everything a document needs to say.
 * `firefly.security.http_basic.enabled`, `firefly.security.jwt.enabled`,
 * `firefly.security.oauth2.resource_server.*` and `firefly.security.http.rules` are CONFIGURATION, and the
 * Config port is shared by every package in this framework. This class reads them and nothing else — no
 * Security class is imported, no Security bean is resolved, and `deptrac` sees no new edge for any of it.
 *
 * WHAT IT EMITS
 *
 *   - `httpBasic`  — `{type: http, scheme: basic}`, when `http_basic.enabled`.
 *   - `bearerAuth` — `{type: http, scheme: bearer, bearerFormat: JWT}`, when `jwt.enabled` (the local
 *     bearer filter). Named as OpenAPI tooling conventionally names it, because a generated client's
 *     property name comes from here.
 *   - `oauth2ResourceServer` — the same http/bearer shape, with the issuer and audience in the description
 *     so a reader knows WHICH tokens are accepted. Deliberately NOT `type: oauth2`: a resource server does
 *     not issue tokens and has no flow URLs to publish; describing it as an oauth2 scheme with an empty
 *     `flows` object produces a document Swagger UI renders as an un-fillable form. A package that DOES
 *     hold those URLs — an authorization server — can publish a real `type: oauth2` scheme through
 *     SecuritySchemeContributor; none ships today, and this class never invents one on its behalf.
 *
 * WHAT IT REQUIRES, PER OPERATION. `firefly.security.http.rules` is first-match-wins over `Str::is()`
 * patterns and is DENY BY DEFAULT once `http.enabled` is on — so a path that matches a `permitAll` rule is
 * public, a path that matches any other rule needs the scheme, and a path that matches NO rule needs it too,
 * because that is precisely what HttpSecurityFilter does to it at runtime. A `hasScope:` rule contributes
 * its scope to the requirement's scope list, which is the one place an OpenAPI requirement can carry more
 * than a name. With `http.enabled` off this class returns null — no opinion — rather than an empty list,
 * because the absence of URL rules says nothing about whether a method rule protects the handler.
 *
 * A RULE THE FILTER CANNOT MATCH DOES NOT OPEN A PATH HERE EITHER. The generator is asked about route
 * TEMPLATES and the filter only ever sees request paths, so a pattern spelled `/api/orders/{id}` matches
 * nothing at runtime — and is made to match nothing here as well, rather than publishing the operation as
 * public because its text happens to equal the template's. See accessFor(): fail-open documentation is the
 * single failure this class must not have.
 *
 * EVERY CONFIGURED SCHEME IS NAMED, NOT JUST ONE. An operation's `security` array is an OR-list — satisfying
 * any entry satisfies the operation — and an application with `http_basic.enabled` beside `jwt.enabled` will
 * really accept EITHER credential, because HttpBasicFilter and JwtAuthenticationFilter each skip an
 * `Authorization` header belonging to the other scheme. Naming only one of them would leave the other as an
 * orphan in `components.securitySchemes`: published, referenced by nothing, and unusable by a generated
 * client whose author has no way of knowing the server would have accepted it. Both maps are therefore built
 * from ONE list of configured schemes — schemes() turns it into the definitions, requirementsFor() into the
 * names — so a scheme this class publishes and a scheme it requires cannot drift apart.
 *
 * It also returns null when URL rules are on but NOTHING it can name is configured — a surface protected by
 * a session and a form login, which OpenAPI has no scheme for that would mean anything to a generated
 * client. Naming nothing is the honest answer there; naming a scheme the server does not accept would be a
 * lie a client acts on.
 *
 * WEBHOOKS AND CALLBACKS STAY OUT, and not for want of a seam: they describe an application's own OUTBOUND
 * contracts — the requests IT sends to somebody else — and no manifest in this framework records those. A
 * generator that invented them would be documenting code that does not exist.
 */
final class ConfiguredSecurity implements SecurityRequirementContributor, SecuritySchemeContributor
{
    /**
     * What every `{placeholder}` in a route template is replaced with before a rule pattern is matched
     * against it — see accessFor(), which is where the reason lives.
     *
     * A NUL byte, because the requirement is precisely "a string no literal pattern segment can match": a URL
     * cannot carry one, a rule pattern written by a human does not contain one, and `Str::is()` compiles a
     * pattern's `*` to `.*`, which matches it. So a wildcard covering the placeholder position still matches
     * and anything else — including the placeholder spelled out literally — does not.
     */
    private const string PLACEHOLDER = "\0";

    /** The `access` spelling of a URL rule that demands one OAuth2 scope — see scopeName(). */
    private const string SCOPE_RULE = 'hasScope:';

    /**
     * The prefix an OAuth2 scope wears as a GRANTED AUTHORITY, which a rule may spell and a document may
     * not. See scopeName().
     */
    private const string SCOPE_AUTHORITY = 'SCOPE_';

    public function __construct(private readonly Config $config) {}

    /**
     * The `components.securitySchemes` entries, which are exactly the schemes requirementsFor() names — see
     * configuredSchemes(), the single list both read.
     *
     * @return list<SecurityScheme>
     */
    public function schemes(): array
    {
        return $this->configuredSchemes();
    }

    /**
     * @return list<SecurityRequirement>|null
     */
    public function requirementsFor(RouteDescriptor $route): ?array
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.http.enabled', false)) {
            return null;
        }

        $schemes = $this->configuredSchemes();
        if ($schemes === []) {
            return null;
        }

        $access = $this->accessFor($route->path);

        if ($access === 'permitAll') {
            return [];
        }

        $scopes = str_starts_with($access, self::SCOPE_RULE) ? $this->scopeName(substr($access, strlen(self::SCOPE_RULE))) : [];

        return array_map(
            // The scope list rides on the TOKEN-shaped schemes only. A `hasScope:` rule tests for a `SCOPE_x`
            // authority, which a bearer token carries as a claim a client can ask the token endpoint for;
            // HTTP Basic has no scope vocabulary a generated client could request, so naming one beside it
            // would publish a parameter nobody can supply.
            static fn (SecurityScheme $scheme): SecurityRequirement => new SecurityRequirement(
                $scheme->name,
                ($scheme->definition['scheme'] ?? null) === 'bearer' ? $scopes : [],
            ),
            $schemes,
        );
    }

    /**
     * The OAUTH2 SCOPE a `hasScope:` rule demands, as a scope list — which is not always the name the rule
     * spells.
     *
     * The rule's argument reaches the SAME evaluator a `#[PreAuthorize("hasScope('…')")]` does, and that
     * evaluator normalises a bare scope to the `SCOPE_x` AUTHORITY a bearer token grants, exactly as it
     * normalises `ADMIN` to `ROLE_ADMIN`. So `hasScope:SCOPE_orders.read` and `hasScope:orders.read` are the
     * same enforced rule, and the prefixed spelling is a documented one. A Security Requirement Object's
     * scope list is not an authority list: it is what a generated client asks the authorization server for,
     * and no client registration holds a scope called `SCOPE_orders.read`. Publishing the prefix verbatim
     * sends Swagger UI's Authorize dialog after a scope that does not exist — and, where an authorization
     * server publishes a `type: oauth2` scheme beside this one, SecurityModel unions the stated name into
     * that scheme's flow scopes, so the document DECLARES the impossible scope as well and the authorization
     * request is refused.
     *
     * An empty scope — a rule of exactly `hasScope:`, or `hasScope:SCOPE_` whose remainder is nothing —
     * names none, and an empty list is what "this path needs the credential, and nothing more can be said"
     * already means everywhere else here.
     *
     * The method-rule contributor in firefly/security carries the same rule for the expression spelling; the
     * two cannot share it, because packages/openapi may not see that package, and what keeps them agreeing
     * is a capstone that generates a document with both contributors on it.
     *
     * @return list<string>
     */
    private function scopeName(string $access): array
    {
        $scope = str_starts_with($access, self::SCOPE_AUTHORITY)
            ? substr($access, strlen(self::SCOPE_AUTHORITY))
            : $access;

        return $scope === '' ? [] : [$scope];
    }

    /**
     * The schemes `firefly.security.*` actually configures, in the order an operation's OR-list names them:
     * the bearer ones first, because a bearer token is what an API client holds and what a generated client's
     * first-listed option should be, then HTTP Basic. This is NOT a precedence and nothing here claims it is
     * — the entries are alternatives, any one of which gets a caller in.
     *
     * WHY NOT PICK ONE "PRIMARY" SCHEME, the obvious shape, and the one to keep rejecting: there is nothing
     * true to pick it by. Filter order does not say it — HttpBasicFilter is #[Order(-91)],
     * JwtAuthenticationFilter −90 and OAuth2ResourceServerFilter −85, and Container::getAll() sorts
     * ascending, so Basic runs FIRST, the opposite of the ordering such a pick would want. Nor does the
     * challenge a caller gets: DelegatingAuthenticationEntryPoint decides that, not this class. And the
     * document would still publish the schemes it declined to name, leaving a generated client an option
     * nothing declares usable while its holder is told to go and get a different credential.
     *
     * jwt and resource_server are mutually exclusive at boot — SecurityWiringPass refuses the pair — so this
     * list holds at most one bearer scheme, beside HTTP Basic at most once.
     *
     * @return list<SecurityScheme>
     */
    private function configuredSchemes(): array
    {
        if (! $this->config->bool('firefly.security.enabled', false)) {
            return [];
        }

        $schemes = [];

        if ($this->config->bool('firefly.security.oauth2.resource_server.enabled', false)) {
            $schemes[] = new SecurityScheme('oauth2ResourceServer', [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => $this->resourceServerDescription(),
            ]);
        }

        if ($this->config->bool('firefly.security.jwt.enabled', false)) {
            $schemes[] = new SecurityScheme('bearerAuth', ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']);
        }

        if ($this->config->bool('firefly.security.http_basic.enabled', false)) {
            $schemes[] = new SecurityScheme('httpBasic', ['type' => 'http', 'scheme' => 'basic']);
        }

        return $schemes;
    }

    /**
     * The access verb the first matching rule carries, or 'denyAll' when nothing matches — which is what the
     * filter does, and therefore what the document must say.
     *
     * BOTH SIDES OF THE MATCH ARE PUT IN `$request->path()` FORM, because that is the string
     * HttpSecurityFilter matches a rule against, and matching anything else would make this document
     * disagree with the server it describes:
     *
     *   - the ROUTE, because a RouteManifest path carries a leading slash and `$request->path()` never does
     *     (`'/'` for the root, and `'api/orders'` — no slash — for everything else);
     *   - the PATTERN, because firefly/security normalises it the same way at the point every rule is built
     *     (HttpSecurity::requestMatcher(), which anyRequest() and fromConfig() both call), so `/api/*` and
     *     `api/*` are one rule at runtime and must be one rule here too.
     *
     * The normalisation is REPEATED rather than shared on purpose: `deptrac.yaml` permits this package no
     * edge to firefly/security, and reading a config value is not one. Repeating four lines is the price of
     * that, and the security package's own tests pin the behaviour this copy mirrors — change one and the
     * other's tests are where the disagreement shows up.
     *
     * A ROUTE TEMPLATE IS NOT A PATH, and that difference is the one way this method could publish a lie.
     * `$route->path` is `/api/orders/{id}`; the filter will only ever see `api/orders/7`. A rule spelled
     * `['pattern' => '/api/orders/{id}', 'access' => 'permitAll']` therefore matches the TEMPLATE literally
     * while matching no request the route can ever receive: the document would call the operation public and
     * the filter would answer 401 to every caller of it — fail-OPEN documentation, which is exactly the lie
     * this class exists to prevent, and a spelling operators reach for precisely because the leading-slash
     * form they are now told to write is the RouteManifest's form, placeholders and all.
     *
     * So every placeholder is replaced with PLACEHOLDER — a byte no literal pattern can match — before the
     * match runs. `api/orders/*` still covers the operation, because a wildcard covering the placeholder
     * position covers every path the route produces; `api/orders/{id}` and `api/orders/1*` no longer do,
     * because neither covers all of them. A rule that is dead for the filter is now dead for the document,
     * which is the only relationship between the two that cannot mislead: an operation the rules do not
     * really open falls through to deny-by-default here just as the request will at runtime.
     *
     * The replacement is per TOKEN rather than per segment, so a templated file extension (`files/{name}.json`)
     * is still covered by `files/*.json`. Soundness does not depend on the granularity — a literal cannot
     * match PLACEHOLDER wherever it is put — only the strictness does.
     */
    private function accessFor(string $path): string
    {
        $candidate = ltrim($path, '/');
        if ($candidate === '') {
            $candidate = '/'; // the root path, which `$request->path()` answers as '/' and never as ''
        }

        $candidate = (string) preg_replace('/\{[^}]*\}/', self::PLACEHOLDER, $candidate);

        /** @var array<mixed> $rules */
        $rules = $this->config->array('firefly.security.http.rules', []);

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            /** @var mixed $pattern */
            $pattern = $rule['pattern'] ?? null;
            /** @var mixed $access */
            $access = $rule['access'] ?? null;

            if (! is_string($pattern) || ! is_string($access)) {
                continue;
            }

            $normalised = ltrim($pattern, '/');

            if (Str::is($normalised === '' ? '/' : $normalised, $candidate)) {
                return $access;
            }
        }

        return 'denyAll';
    }

    private function resourceServerDescription(): string
    {
        $issuer = $this->config->string('firefly.security.oauth2.resource_server.issuer', '');
        $audience = $this->config->string('firefly.security.oauth2.resource_server.audience', '');

        $parts = ['A bearer token validated against the issuer\'s JWKS.'];
        if ($issuer !== '') {
            $parts[] = 'Issuer: '.$issuer.'.';
        }
        if ($audience !== '') {
            $parts[] = 'Audience: '.$audience.'.';
        }

        return implode(' ', $parts);
    }
}
