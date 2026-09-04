<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\EdgeFixture\Alpha;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Half of the operationId-collision pair. Its short name and method name are identical to
 * Beta\ReportController's, so OpenApiGenerator::derivedId() produces the SAME candidate id for both — the
 * exact shape a real app hits when two modules each expose a ReportController.
 *
 * It also carries the only OPTIONAL path variable in the test surface (`{slug?}`), because OpenAPI has no
 * spelling for one: a path parameter is required there, full stop.
 */
#[RestController]
#[RequestMapping('/alpha/reports')]
final class ReportController
{
    /** @return array<string, mixed> */
    #[GetMapping('/{slug?}')]
    public function index(#[PathVariable] ?string $slug = null): array
    {
        return ['slug' => $slug];
    }
}
