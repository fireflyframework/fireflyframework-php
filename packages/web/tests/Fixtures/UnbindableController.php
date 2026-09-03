<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Routes the body DTO the plan genuinely CANNOT describe — $counter is typed as an interface, so there is no
 * class to construct from the sub-array. This is the "plan cannot say" path: the framework owes the client a
 * clean 400, not a TypeError rendered as a 500 quoting an absolute filesystem path.
 */
#[RestController]
#[RequestMapping('/unbindable')]
final class UnbindableController
{
    /** @return array<string,mixed> */
    #[PostMapping(status: 201)]
    public function create(#[RequestBody] UnbindableRequest $body): array
    {
        return ['name' => $body->name];
    }
}
