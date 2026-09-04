<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\KeywordFixture;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Keywords.
 *
 * A real controller so the keyword payload is reached the way an application reaches one — through the real
 * RouteScanner and the real generator — rather than by handing a hand-written node to a private method.
 */
#[RestController]
#[RequestMapping('/api/keywords')]
final class KeywordController
{
    /** @return array<string, mixed> */
    #[PostMapping(status: 201)]
    public function create(#[Valid] #[RequestBody] KeywordRequest $body): array
    {
        return ['reference' => $body->reference];
    }
}
