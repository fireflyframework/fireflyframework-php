<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\EdgeFixture\Beta;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * The other half of the operationId-collision pair — same short name, same method name, different namespace
 * and a different path, so the RouteManifest legitimately holds two routes that both want `reportIndex`.
 */
#[RestController]
#[RequestMapping('/beta/reports')]
final class ReportController
{
    /** @return array<string, mixed> */
    #[GetMapping]
    public function index(): array
    {
        return [];
    }
}
