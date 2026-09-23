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

        $scopes = str_starts_with($access, 'hasScope:') ? [substr($access, 9)] : [];

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
     */
    private function accessFor(string $path): string
    {
        $candidate = ltrim($path, '/');
        if ($candidate === '') {
            $candidate = '/'; // the root path, which `$request->path()` answers as '/' and never as ''
        }

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
