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
}
