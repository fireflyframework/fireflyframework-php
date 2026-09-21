<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Principal;

use Firefly\Security\Core\Attributes\AuthenticationPrincipal;
use Firefly\Security\Core\Attributes\CurrentSecurityContext;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\User\UserDetails;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

/** Every way a controller can ask for the principal, as Spring's @AuthenticationPrincipal / @CurrentSecurityContext allow. */
#[RestController]
final class ProfileController
{
    /** @return array{name: string, authorities: list<string>, user: string|null, principal: string, authenticated: bool} */
    #[GetMapping('/profile')]
    public function profile(Authentication $auth, ?UserDetails $user, #[AuthenticationPrincipal] mixed $principal, #[CurrentSecurityContext] SecurityContext $context): array
    {
        return [
            'name' => $auth->getName(),
            'authorities' => $auth->authorityStrings(),
            'user' => $user?->getUsername(),
            'principal' => is_string($principal) ? $principal : ($principal instanceof UserDetails ? 'details:'.$principal->getUsername() : get_debug_type($principal)),
            'authenticated' => $context->isAuthenticated(),
        ];
    }

    /** @return array{who: string} */
    #[GetMapping('/open/greeting')]
    public function greeting(?Authentication $auth): array
    {
        return ['who' => $auth?->getName() ?? 'stranger'];
    }

    /**
     * The JWT `sub` case the attribute describes: a nullable SCALAR principal, which the scanner would
     * otherwise plan as a query parameter.
     *
     * @return array{sub: string|null}
     */
    #[GetMapping('/open/sub')]
    public function sub(#[AuthenticationPrincipal] ?string $sub): array
    {
        return ['sub' => $sub];
    }

    /**
     * The declaration the attribute recommends for a UserDetails principal, and one this suite feeds BOTH
     * kinds: a form login's User, which it receives, and a JWT's bare `sub` string, which is not a UserDetails
     * and must be the null the declaration allowed for — never a TypeError from the dispatcher.
     *
     * @return array{user: string|null}
     */
    #[GetMapping('/open/principal-user')]
    public function principalUser(#[AuthenticationPrincipal] ?UserDetails $user): array
    {
        return ['user' => $user?->getUsername()];
    }

    /** @return array{principal: string|null} */
    #[GetMapping('/open/whoami')]
    public function whoami(#[AuthenticationPrincipal] mixed $principal): array
    {
        return ['principal' => is_string($principal) ? $principal : ($principal instanceof UserDetails ? 'details:'.$principal->getUsername() : ($principal === null ? null : get_debug_type($principal)))];
    }

    /** @return array{authenticated: bool, name: string|null} */
    #[GetMapping('/open/context')]
    public function context(#[CurrentSecurityContext] SecurityContext $context): array
    {
        return ['authenticated' => $context->isAuthenticated(), 'name' => $context->getAuthentication()?->getName()];
    }

    /** @return array{user: string|null} */
    #[GetMapping('/open/user')]
    public function user(?UserDetails $user): array
    {
        return ['user' => $user?->getUsername()];
    }

    /** @return array{name: string} */
    #[GetMapping('/open/required')]
    public function required(Authentication $auth): array
    {
        return ['name' => $auth->getName()];
    }
}
