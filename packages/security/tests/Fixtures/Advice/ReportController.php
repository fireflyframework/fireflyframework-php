<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

/** A final controller with a rule: the dispatcher enforces it, so the proxy plan must leave it alone. */
#[RestController]
final class ReportController
{
    /** @return list<Report> */
    #[PreAuthorize("hasRole('ADMIN')")]
    #[GetMapping('/advice/reports')]
    public function index(): array
    {
        return [];
    }
}
