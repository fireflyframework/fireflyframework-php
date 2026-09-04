<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Routes the self-referential NodeRequest so the cycle guard is exercised on the PRODUCTION path — the
 * scanner walking a class that points at itself — rather than only against a hand-authored shape table.
 */
#[RestController]
#[RequestMapping('/nodes')]
final class NodesController
{
    /** @return array<string,mixed> */
    #[PostMapping(status: 201)]
    public function create(#[Valid] #[RequestBody] NodeRequest $body): array
    {
        $depth = 0;
        for ($node = $body; $node !== null; $node = $node->child) {
            $depth++;
        }

        return ['label' => $body->label, 'depth' => $depth];
    }
}
