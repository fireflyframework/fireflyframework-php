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
 *   UserDetails $user / App\User $user (a UserDetails)     → the principal when it is one, else null
 *
 * A null for a parameter the plan says is NOT nullable is answered as a 401 rather than a TypeError: a
 * controller that declares `Authentication $auth` has said the action needs a principal.
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
            return $this->orRefuse($authentication?->getPrincipal(), $binding);
        }

        if ($type === Authentication::class) {
            return $this->orRefuse($context->isAuthenticated() ? $authentication : null, $binding);
        }

        $principal = $authentication?->getPrincipal();
        $user = $principal instanceof UserDetails && (! is_string($type) || $principal instanceof $type) ? $principal : null;

        return $this->orRefuse($user, $binding);
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
