<?php

declare(strict_types=1);

namespace Firefly\Security\Access;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * A fluent, deny-by-default URL-authorization DSL (Spring's HttpSecurity.authorizeHttpRequests). Each
 * requestMatcher(pattern) opens a pending rule that the next access verb (permitAll/denyAll/authenticated/
 * hasRole/hasAuthority/hasScope) finalises into a UrlAuthorizationRule whose `expression` reuses the exact same
 * grammar the method-security evaluator runs — one authorization engine, two entry points. build() returns the
 * rules in declaration order (first match wins); an app should end with anyRequest()->denyAll()/authenticated().
 * fromConfig() reads the same verbs as `firefly.security.http.rules` access specs: `permitAll`, `denyAll`,
 * `authenticated`, `hasRole:<role>`, `hasAuthority:<authority>` and `hasScope:<scope>` (the `SCOPE_x`
 * authority a bearer token or an OAuth2 login granted); anything else denies.
 *
 * Patterns are normalised to the leading-slash-free spelling the filter matches against — see
 * normalisePattern(); `/api/*` and `api/*` are the same rule, and `/` still means the root path.
 */
final class HttpSecurity
{
    /** @var list<UrlAuthorizationRule> */
    private array $rules = [];

    private ?string $pending = null;

    public static function create(): self
    {
        return new self;
    }

    public function requestMatcher(string $pattern): self
    {
        $this->pending = self::normalisePattern($pattern);

        return $this;
    }

    public function anyRequest(): self
    {
        return $this->requestMatcher('*');
    }

    public function permitAll(): self
    {
        return $this->finalise('permitAll()');
    }

    public function denyAll(): self
    {
        return $this->finalise('denyAll()');
    }

    public function authenticated(): self
    {
        return $this->finalise('isAuthenticated()');
    }

    public function hasRole(string $role): self
    {
        return $this->finalise("hasRole('".self::assertSafeValue($role)."')");
    }

    public function hasAuthority(string $authority): self
    {
        return $this->finalise("hasAuthority('".self::assertSafeValue($authority)."')");
    }

    public function hasScope(string $scope): self
    {
        return $this->finalise("hasScope('".self::assertSafeValue($scope)."')");
    }

    /**
     * @return list<UrlAuthorizationRule>
     */
    public function build(): array
    {
        return $this->rules;
    }

    /**
     * @param  list<array{pattern:string,access:string}>  $rules
     */
    public static function fromConfig(array $rules): self
    {
        $http = new self;
        foreach ($rules as $rule) {
            $http->requestMatcher($rule['pattern'])->finalise(self::expressionFor($rule['access']));
        }

        return $http;
    }

    private static function expressionFor(string $access): string
    {
        return match (true) {
            $access === 'permitAll' => 'permitAll()',
            $access === 'denyAll' => 'denyAll()',
            $access === 'authenticated' => 'isAuthenticated()',
            str_starts_with($access, 'hasRole:') => "hasRole('".self::assertSafeValue(substr($access, 8))."')",
            str_starts_with($access, 'hasAuthority:') => "hasAuthority('".self::assertSafeValue(substr($access, 13))."')",
            str_starts_with($access, 'hasScope:') => "hasScope('".self::assertSafeValue(substr($access, 9))."')",
            default => 'denyAll()', // fail-closed: an unrecognised access spec denies
        };
    }

    /**
     * EVERY PATTERN IS STORED IN THE SHAPE `$request->path()` HANDS THE FILTER — leading slashes off, because
     * HttpSecurityFilter matches `Str::is($rule->pattern, $request->path())` and Laravel's `path()` never
     * carries one. Without this, `['pattern' => '/actuator/health', 'access' => 'permitAll']` — the spelling
     * half the world writes, and the spelling every route in a RouteManifest uses — is a DEAD rule:
     * `Str::is('/actuator/health', 'actuator/health')` is false, the request matches nothing, deny-by-default
     * refuses it, and the operator reads a 401 on the one path they explicitly opened. The failure is silent
     * (a dead rule looks exactly like a rule that did not apply) and it is also the failure mode that makes a
     * generated OpenAPI document lie: firefly/openapi reads these same rules to decide which operations
     * publish a `security` requirement, and a rule that means one thing to the document and nothing to the
     * filter is a published claim about a path the server does not honour.
     *
     * Root is the one path that KEEPS its slash: `$request->path()` answers `'/'` for it, never `''`, so a
     * `'/'` pattern must stay `'/'` rather than normalising to an empty string that matches nothing.
     *
     * Normalising here rather than in the filter is deliberate — this is the ONE door every rule comes
     * through (`anyRequest()` and `fromConfig()` both call it), so a rule is in canonical form from the
     * moment it exists, and anything that reads `UrlAuthorizationRule::$pattern` afterwards — the filter, a
     * test, an actuator endpoint — sees the same spelling the matcher will use.
     */
    private static function normalisePattern(string $pattern): string
    {
        $normalised = ltrim($pattern, '/');

        return $normalised === '' ? '/' : $normalised;
    }

    /**
     * Every value interpolated into a single-quoted expression literal (hasRole()/hasAuthority()/hasScope(),
     * including the fromConfig() access-spec path) must be rejected if it contains a quote: a legitimate
     * role/authority/scope never does (Spring authorities are ROLE_X / SCOPE_x / resource:action), but a quote
     * would let the value break out of its string literal and splice extra grammar into the fixed expression —
     * e.g. widening it with `or permitAll()`.
     */
    private static function assertSafeValue(string $value): string
    {
        if (str_contains($value, "'")) {
            throw new ConfigurationException("Illegal character in security authority/role value: {$value}");
        }

        return $value;
    }

    private function finalise(string $expression): self
    {
        if ($this->pending === null) {
            throw new \LogicException('Call requestMatcher()/anyRequest() before an access rule.');
        }
        $this->rules[] = new UrlAuthorizationRule($this->pending, $expression);
        $this->pending = null;

        return $this;
    }
}
