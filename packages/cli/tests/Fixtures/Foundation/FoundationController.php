<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RestController;

/**
 * The foundation slice's HTTP surface: a public GET / (200), a #[Valid] POST /widgets (422 on a bad body), and a
 * #[PreAuthorize]-guarded GET /admin. Attribute usage is copied verbatim from the web + security capstone fixtures
 * (AccountsController + SecuredController); on the cached path the route + method-security rules come from the
 * compiled routes.php / security-methods.php.
 */
#[RestController]
final class FoundationController
{
    /** @return array<string,string> */
    #[GetMapping('/')]
    public function home(): array
    {
        return ['status' => 'ok'];
    }

    /** @return array<string,string> */
    #[PostMapping('/widgets', status: 201)]
    public function create(#[Valid] #[RequestBody] CreateWidgetRequest $body): array
    {
        return ['name' => $body->name];
    }

    /** @return array<string,string> */
    #[GetMapping('/admin')]
    #[PreAuthorize("hasRole('ADMIN')")]
    public function admin(): array
    {
        return ['area' => 'admin'];
    }
}
