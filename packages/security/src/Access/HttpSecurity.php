<?php

declare(strict_types=1);

namespace Firefly\Security\Access;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * A fluent, deny-by-default URL-authorization DSL (Spring's HttpSecurity.authorizeHttpRequests). Each
 * requestMatcher(pattern) opens a pending rule that the next access verb (permitAll/denyAll/authenticated/
 * hasRole/hasAuthority) finalises into a UrlAuthorizationRule whose `expression` reuses the exact same grammar
 * the method-security evaluator runs — one authorization engine, two entry points. build() returns the rules in
 * declaration order (first match wins); an app should end with anyRequest()->denyAll()/authenticated().
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
        $this->pending = $pattern;

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
            default => 'denyAll()', // fail-closed: an unrecognised access spec denies
        };
    }

    /**
     * Every value interpolated into a single-quoted expression literal (hasRole()/hasAuthority(), including the
     * fromConfig() access-spec path) must be rejected if it contains a quote: a legitimate role/authority never
     * does (Spring authorities are ROLE_X / resource:action), but a quote would let the value break out of its
     * string literal and splice extra grammar into the fixed expression — e.g. widening it with `or permitAll()`.
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
