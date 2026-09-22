<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Tests\Fixtures\Flows;

use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Security\Core\Attributes\AuthenticationPrincipal;
use Firefly\Security\OAuth2\Client\User\OAuth2User;
use Firefly\Security\OAuth2\Client\User\OidcUser;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Every way an application reads an OAuth2 principal: `#[AuthenticationPrincipal] OidcUser` (a 401 when the
 * principal is not one), the nullable `?OAuth2User` that serves both kinds, `hasScope` as a URL rule (the
 * capstone's `api/scoped`) and as a method rule, and a role a GrantedAuthoritiesMapper must have added.
 */
#[RestController]
final class AccountController
{
    /** @return array{name: string, subject: string, email: ?string, fullName: ?string, groups: mixed, idTokenValue: string, hasUserInfo: bool, issuer: mixed} */
    #[GetMapping('/account')]
    public function account(#[AuthenticationPrincipal] OidcUser $user): array
    {
        return [
            'name' => $user->getName(),
            'subject' => $user->getSubject(),
            'email' => $user->getEmail(),
            'fullName' => $user->getFullName(),
            'groups' => $user->getClaim('groups'),
            'idTokenValue' => $user->getIdToken()->getTokenValue(),
            'hasUserInfo' => $user->getUserInfo() !== null,
            'issuer' => $user->getAttribute('iss'),
        ];
    }

    /** @return array{name: ?string, kind: string} */
    #[GetMapping('/account/any')]
    public function any(#[AuthenticationPrincipal] ?OAuth2User $user): array
    {
        return ['name' => $user?->getName(), 'kind' => $user === null ? 'none' : ($user instanceof OidcUser ? 'oidc' : 'oauth2')];
    }

    /** @return array{scoped: bool} */
    #[GetMapping('/api/scoped')]
    public function scoped(): array
    {
        return ['scoped' => true];
    }

    /** @return array{email: bool} */
    #[PreAuthorize("hasScope('email')")]
    #[GetMapping('/api/email')]
    public function email(): array
    {
        return ['email' => true];
    }

    /** @return array{engineers: bool} */
    #[PreAuthorize("hasRole('ENGINEERING')")]
    #[GetMapping('/api/engineers')]
    public function engineers(): array
    {
        return ['engineers' => true];
    }
}
