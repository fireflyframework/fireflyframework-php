<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Flows;

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

/** Reports the current principal as JSON, so a flow test can read who the filters established. */
#[RestController]
final class WhoAmIController
{
    /** @return array{name: string, authorities: list<string>, authenticated: bool} */
    #[GetMapping('/whoami')]
    public function whoami(): array
    {
        $authentication = SecurityContextHolder::getAuthentication();

        return [
            'name' => $authentication?->getName() ?? 'anonymous',
            'authorities' => $authentication?->authorityStrings() ?? [],
            'authenticated' => SecurityContextHolder::getContext()->isAuthenticated(),
        ];
    }
}
