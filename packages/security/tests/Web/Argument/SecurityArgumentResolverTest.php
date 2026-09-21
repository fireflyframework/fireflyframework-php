<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Core\Attributes\AuthenticationPrincipal;
use Firefly\Security\Core\Attributes\CurrentSecurityContext;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\User\User;
use Firefly\Security\User\UserDetails;
use Firefly\Security\Web\Argument\SecurityArgumentResolver;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * @param  list<class-string>  $attributes
 * @return array<string, mixed>
 */
function binding(?string $type, array $attributes = [], bool $nullable = false): array
{
    $binding = ['name' => 'p', 'kind' => 'service', 'key' => (string) $type, 'type' => $type, 'required' => true, 'default' => null, 'valid' => false, 'properties' => []];
    if ($attributes !== []) {
        $binding['attributes'] = $attributes;
    }
    if ($nullable) {
        $binding['nullable'] = true;
    }

    return $binding;
}

/**
 * resolve() answers mixed by contract; the context it hands back is read through the type it must have.
 *
 * @param  array<string, mixed>  $binding
 */
function resolvedContext(SecurityArgumentResolver $resolver, array $binding, Request $request): ?SecurityContext
{
    $context = $resolver->resolve($binding, $request);

    return $context instanceof SecurityContext ? $context : null;
}

it('supports the two attributes and the three security types, and nothing else', function () {
    $resolver = new SecurityArgumentResolver;

    expect($resolver->supports(binding(Authentication::class)))->toBeTrue()
        ->and($resolver->supports(binding(SecurityContext::class)))->toBeTrue()
        ->and($resolver->supports(binding(UserDetails::class)))->toBeTrue()
        ->and($resolver->supports(binding(User::class)))->toBeTrue()
        ->and($resolver->supports(binding('mixed', [AuthenticationPrincipal::class])))->toBeTrue()
        ->and($resolver->supports(binding(null, [CurrentSecurityContext::class])))->toBeTrue()
        ->and($resolver->supports(binding('int')))->toBeFalse()
        ->and($resolver->supports(binding(stdClass::class)))->toBeFalse();
});

it('resolves the context, the authentication, the principal and the user for a signed-in request', function () {
    $user = new User('ada', '{noop}x', [new SimpleGrantedAuthority('ROLE_USER')]);
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('ada', $user, $user->getAuthorities())));
    $resolver = new SecurityArgumentResolver;
    $request = Request::create('/x', 'GET');

    expect($resolver->resolve(binding(Authentication::class), $request))->toBeInstanceOf(Authentication::class)
        ->and(resolvedContext($resolver, binding(SecurityContext::class), $request)?->isAuthenticated())->toBeTrue()
        ->and($resolver->resolve(binding(null, [CurrentSecurityContext::class]), $request))->toBeInstanceOf(SecurityContext::class)
        ->and($resolver->resolve(binding('mixed', [AuthenticationPrincipal::class]), $request))->toBe($user)
        ->and($resolver->resolve(binding(UserDetails::class), $request))->toBe($user)
        ->and($resolver->resolve(binding(User::class), $request))->toBe($user);
});

it('answers null for anonymous where the parameter allows it, the anonymous context always, and a 401 otherwise', function () {
    $resolver = new SecurityArgumentResolver;
    $request = Request::create('/x', 'GET');

    expect($resolver->resolve(binding(Authentication::class, nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding(UserDetails::class, nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding('mixed', [AuthenticationPrincipal::class]), $request))->toBeNull()
        // An untyped parameter is `mixed` in PHP's own rules, and accepts null the same way.
        ->and($resolver->resolve(binding(null, [AuthenticationPrincipal::class]), $request))->toBeNull()
        // A nullable SCALAR principal — `?string $sub`, the JWT case — is null too: the scanner records
        // `nullable` for an attributed binding whatever kind its type planned, and the resolver honours it.
        ->and($resolver->resolve(binding('string', [AuthenticationPrincipal::class], nullable: true), $request))->toBeNull()
        ->and(resolvedContext($resolver, binding(SecurityContext::class), $request)?->isAuthenticated())->toBeFalse()
        ->and(fn () => $resolver->resolve(binding(Authentication::class), $request))->toThrow(AuthenticationException::class)
        // ...and a scalar principal that cannot take null is the same 401, not a TypeError.
        ->and(fn () => $resolver->resolve(binding('string', [AuthenticationPrincipal::class]), $request))->toThrow(AuthenticationException::class);

    // A principal that is a bare string (a JWT `sub`) is not a UserDetails: the typed parameter gets null.
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('svc', 'svc', [])));

    expect($resolver->resolve(binding(UserDetails::class, nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding('mixed', [AuthenticationPrincipal::class]), $request))->toBe('svc');
});

it('hands an attributed principal over only when it is what the parameter declares, and null otherwise', function () {
    $resolver = new SecurityArgumentResolver;
    $request = Request::create('/x', 'GET');

    // A JWT's bare `sub` string against `#[AuthenticationPrincipal] ?UserDetails $user` — the declaration the
    // attribute's own docblock recommends — is null, never the string a `?UserDetails` parameter refuses with a
    // TypeError (a 500 from the dispatcher); against the non-nullable form it is the documented 401. The same
    // string fits `?string`, `string` and `mixed` and is handed over unchanged.
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('svc', 'svc', [])));

    expect($resolver->resolve(binding(UserDetails::class, [AuthenticationPrincipal::class], nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding(User::class, [AuthenticationPrincipal::class], nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding('int', [AuthenticationPrincipal::class], nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding('object', [AuthenticationPrincipal::class], nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding('string', [AuthenticationPrincipal::class], nullable: true), $request))->toBe('svc')
        ->and($resolver->resolve(binding('string', [AuthenticationPrincipal::class]), $request))->toBe('svc')
        ->and($resolver->resolve(binding('mixed', [AuthenticationPrincipal::class]), $request))->toBe('svc')
        ->and(fn () => $resolver->resolve(binding(UserDetails::class, [AuthenticationPrincipal::class]), $request))->toThrow(AuthenticationException::class)
        ->and(fn () => $resolver->resolve(binding('int', [AuthenticationPrincipal::class]), $request))->toThrow(AuthenticationException::class);

    // The mirror: a form login's User against `#[AuthenticationPrincipal] ?string $sub` is null (a 401 when the
    // parameter cannot take null), while every declaration the User fits — UserDetails, User itself, object,
    // mixed — receives it; a class it is not an instance of is null like any other mismatch.
    $user = new User('ada', '{noop}x', [new SimpleGrantedAuthority('ROLE_USER')]);
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('ada', $user, $user->getAuthorities())));

    expect($resolver->resolve(binding('string', [AuthenticationPrincipal::class], nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding(stdClass::class, [AuthenticationPrincipal::class], nullable: true), $request))->toBeNull()
        ->and($resolver->resolve(binding(UserDetails::class, [AuthenticationPrincipal::class]), $request))->toBe($user)
        ->and($resolver->resolve(binding(User::class, [AuthenticationPrincipal::class]), $request))->toBe($user)
        ->and($resolver->resolve(binding('object', [AuthenticationPrincipal::class]), $request))->toBe($user)
        ->and($resolver->resolve(binding('mixed', [AuthenticationPrincipal::class]), $request))->toBe($user)
        ->and(fn () => $resolver->resolve(binding('string', [AuthenticationPrincipal::class]), $request))->toThrow(AuthenticationException::class)
        ->and(fn () => $resolver->resolve(binding(stdClass::class, [AuthenticationPrincipal::class]), $request))->toThrow(AuthenticationException::class);
});
