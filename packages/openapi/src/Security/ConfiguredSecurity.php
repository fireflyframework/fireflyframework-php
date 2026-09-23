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
 *     `flows` object produces a document Swagger UI renders as an un-fillable form. The authorization
 *     server, which DOES have those URLs, contributes a real `type: oauth2` scheme of its own.
 *
 * WHAT IT REQUIRES, PER OPERATION. `firefly.security.http.rules` is first-match-wins over `Str::is()`
 * patterns and is DENY BY DEFAULT once `http.enabled` is on — so a path that matches a `permitAll` rule is
 * public, a path that matches any other rule needs the scheme, and a path that matches NO rule needs it too,
 * because that is precisely what HttpSecurityFilter does to it at runtime. A `hasScope:` rule contributes
 * its scope to the requirement's scope list, which is the one place an OpenAPI requirement can carry more
 * than a name. With `http.enabled` off this class returns null — no opinion — rather than an empty list,
 * because the absence of URL rules says nothing about whether a method rule protects the handler.
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
     * @return list<SecurityScheme>
     */
    public function schemes(): array
    {
        if (! $this->config->bool('firefly.security.enabled', false)) {
            return [];
        }

        $schemes = [];

        if ($this->config->bool('firefly.security.http_basic.enabled', false)) {
            $schemes[] = new SecurityScheme('httpBasic', ['type' => 'http', 'scheme' => 'basic']);
        }

        if ($this->config->bool('firefly.security.jwt.enabled', false)) {
            $schemes[] = new SecurityScheme('bearerAuth', ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']);
        }

        if ($this->config->bool('firefly.security.oauth2.resource_server.enabled', false)) {
            $schemes[] = new SecurityScheme('oauth2ResourceServer', [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => $this->resourceServerDescription(),
            ]);
        }

        return $schemes;
    }

    /**
     * @return list<SecurityRequirement>|null
     */
    public function requirementsFor(RouteDescriptor $route): ?array
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.http.enabled', false)) {
            return null;
        }

        $scheme = $this->primaryScheme();
        if ($scheme === null) {
            return null;
        }

        $access = $this->accessFor($route->path);

        if ($access === 'permitAll') {
            return [];
        }

        return [new SecurityRequirement($scheme, str_starts_with($access, 'hasScope:') ? [substr($access, 9)] : [])];
    }

    /**
     * The access verb the first matching rule carries, or 'denyAll' when nothing matches — which is what the
     * filter does, and therefore what the document must say. The route's path is normalised to the
     * leading-slash-free form `Str::is()` patterns in `firefly.security.http.rules` are written against,
     * because HttpSecurityFilter matches them against `$request->path()` and a RouteManifest path carries a
     * slash `$request->path()` never does. The pattern is normalised the same way, so a rule written either
     * spelling covers the operation it covers at runtime.
     */
    private function accessFor(string $path): string
    {
        $candidate = ltrim($path, '/');

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

            if (Str::is(ltrim($pattern, '/'), $candidate)) {
                return $access;
            }
        }

        return 'denyAll';
    }

    /**
     * The scheme an operation-level requirement names when several are configured. The order is the order a
     * request is actually authenticated in — the resource-server filter, then the local jwt filter, then
     * HTTP Basic — so the document names the mechanism a caller reaching a protected path will really be
     * challenged by. (jwt and resource_server are mutually exclusive at boot, so at most one of the first
     * two exists.)
     */
    private function primaryScheme(): ?string
    {
        if ($this->config->bool('firefly.security.oauth2.resource_server.enabled', false)) {
            return 'oauth2ResourceServer';
        }

        if ($this->config->bool('firefly.security.jwt.enabled', false)) {
            return 'bearerAuth';
        }

        return $this->config->bool('firefly.security.http_basic.enabled', false) ? 'httpBasic' : null;
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
