<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Argument;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Core\Attributes\AuthenticationPrincipal;
use Firefly\Security\Core\Attributes\CurrentSecurityContext;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\User\UserDetails;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolver;
use Illuminate\Http\Request;

/**
 * Principal injection for controller actions, from the compiled binding plan and the holder — no reflection:
 *
 *   #[CurrentSecurityContext] $ctx / SecurityContext $ctx  → the context, anonymous when nobody is signed in
 *   Authentication $auth / ?Authentication $auth           → the token, null when anonymous
 *   #[AuthenticationPrincipal] mixed $principal            → Authentication::getPrincipal(), null when anonymous
 *   #[AuthenticationPrincipal] ?UserDetails $user          → the principal when it is one, else null
 *   #[AuthenticationPrincipal] ?string $sub                → the principal when it is a string (a JWT `sub`), else null
 *   UserDetails $user / App\User $user (a UserDetails)     → the principal when it is one, else null
 *
 * An attributed principal is handed over only when it IS what the parameter declares — an instance of a class
 * or interface type, a value of a scalar type — and is null otherwise, as Spring's resolver answers it (its
 * errorOnInvalidType is false by default). The rule is what makes the attribute safe to declare against a type
 * at all: with form login and a bearer filter both on, the same action sees a UserDetails on one request and a
 * bare `sub` string on the next, and the principal that does not fit the declaration must be the null it
 * allowed for — never a TypeError raised from inside the dispatcher's call, which the client sees as a 500.
 *
 * A null for a parameter the plan says is NOT nullable — anonymous, or a principal that does not fit — is
 * answered as a 401 rather than a TypeError: a controller that declares `Authentication $auth` or
 * `#[AuthenticationPrincipal] UserDetails $user` has said the action needs that principal.
 */
final class SecurityArgumentResolver implements HandlerMethodArgumentResolver
{
    public function supports(array $binding): bool
    {
        /** @var list<string> $attributes */
        $attributes = $binding['attributes'] ?? [];
        if (in_array(AuthenticationPrincipal::class, $attributes, true) || in_array(CurrentSecurityContext::class, $attributes, true)) {
            return true;
        }

        /** @var mixed $type */
        $type = $binding['type'] ?? null;
        if (! is_string($type)) {
            return false;
        }

        return $type === Authentication::class
            || $type === SecurityContext::class
            || $type === UserDetails::class
            || is_a($type, UserDetails::class, true);
    }

    public function resolve(array $binding, Request $request): mixed
    {
        /** @var list<string> $attributes */
        $attributes = $binding['attributes'] ?? [];
        /** @var mixed $type */
        $type = $binding['type'] ?? null;
        $context = SecurityContextHolder::getContext();
        $authentication = $context->getAuthentication();

        if (in_array(CurrentSecurityContext::class, $attributes, true) || $type === SecurityContext::class) {
            return $context;
        }

        if (in_array(AuthenticationPrincipal::class, $attributes, true)) {
            $principal = $authentication?->getPrincipal();

            return $this->orRefuse($this->fits($principal, $type) ? $principal : null, $binding);
        }

        if ($type === Authentication::class) {
            return $this->orRefuse($context->isAuthenticated() ? $authentication : null, $binding);
        }

        $principal = $authentication?->getPrincipal();
        $user = $principal instanceof UserDetails && (! is_string($type) || $principal instanceof $type) ? $principal : null;

        return $this->orRefuse($user, $binding);
    }

    /**
     * Whether the principal is what the parameter declares. `mixed`, and the untyped parameter the plan records
     * no type for, take anything; a class or interface type takes an instance of it (an enum counts as a
     * class for class_exists(), and instanceof answers for it); `object` and `iterable` are the two builtins
     * get_debug_type() does not spell, so they are asked directly; every other builtin — string, int, float,
     * bool, array — must be exactly the value's own type, which is the answer PHP's strict call would give
     * (the dispatcher's file declares strict_types, so "42" is not an int there and 1 is not a string).
     */
    private function fits(mixed $principal, mixed $type): bool
    {
        if ($principal === null || ! is_string($type) || $type === 'mixed') {
            return true;
        }

        if (class_exists($type) || interface_exists($type)) {
            return $principal instanceof $type;
        }

        return match ($type) {
            'object' => is_object($principal),
            'iterable' => is_iterable($principal),
            default => get_debug_type($principal) === $type,
        };
    }

    /**
     * A null is refused only where the parameter could not take it: the plan marks a binding `nullable` when
     * its type allows null and it is one a resolver may answer — a service binding, or any binding carrying
     * an attribute firefly/web does not compile itself, so `#[AuthenticationPrincipal] ?string $sub` (planned
     * as a query binding by its type) is null for an anonymous request, not a 401. A `mixed` or untyped
     * parameter (the plan records no type for one, and PHP treats it as `mixed`) accepts null by its own
     * rules, exactly as a nullable one does; checked here as well as in the plan, so a manifest compiled
     * before `nullable` covered attributed bindings answers the same way.
     *
     * @param  array<string, mixed>  $binding
     */
    private function orRefuse(mixed $value, array $binding): mixed
    {
        if ($value !== null || ($binding['nullable'] ?? false)) {
            return $value;
        }

        $type = $binding['type'] ?? null;
        if ($type === null || $type === 'mixed') {
            return null;
        }

        throw new AuthenticationException('Authentication is required.');
    }
}
