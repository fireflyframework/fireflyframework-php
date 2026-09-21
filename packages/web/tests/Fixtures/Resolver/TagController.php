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

    /**
     * A scalar-typed attributed parameter: by its type alone the scanner would plan a query parameter, and
     * the resolver claiming it by the attribute must still be told it may answer null.
     *
     * @return array{label: string|null, page: int}
     */
    #[GetMapping('/tags/labelled')]
    public function labelled(#[Tag] ?string $label, int $page = 1): array
    {
        return ['label' => $label, 'page' => $page];
    }
}
