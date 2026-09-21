<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Resolver;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class TagController
{
    /** @return array{tag: mixed, who: string|null} */
    #[GetMapping('/tags')]
    public function show(#[Tag] mixed $tag, ?\Countable $who = null): array
    {
        return ['tag' => $tag, 'who' => $who === null ? null : count($who).' items'];
    }
}
