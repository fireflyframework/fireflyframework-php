<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Fixtures\Flows;

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

/**
 * The resource-server side of every flow: an API route under `api/*` (authenticated by the capstone's URL rules)
 * that reports the principal the bearer filter established from a token this server issued.
 */
#[RestController]
final class ProfileApiController
{
    /** @return array{sub: string, authorities: list<string>, authenticated: bool} */
    #[GetMapping('/api/profile')]
    public function profile(): array
    {
        $authentication = SecurityContextHolder::getAuthentication();

        return [
            'sub' => $authentication?->getName() ?? 'anonymous',
            'authorities' => $authentication?->authorityStrings() ?? [],
            'authenticated' => SecurityContextHolder::getContext()->isAuthenticated(),
        ];
    }
}
