<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ResolverFixture;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Parameters a registered resolver answers, in the two shapes RouteScanner plans as a QUERY parameter by
 * type alone — `mixed` and a nullable scalar — beside one it does not touch, so the document can be shown to
 * drop exactly the claimed ones and nothing else.
 */
#[RestController]
#[RequestMapping('/tagged')]
final class TaggedController
{
    /** @return array{tag: mixed, label: string|null} */
    #[GetMapping('')]
    public function show(#[Tag] mixed $tag, #[Tag] ?string $label): array
    {
        return ['tag' => $tag, 'label' => $label];
    }

    /** @return array{label: string|null, q: string} */
    #[GetMapping('/search')]
    public function search(#[Tag] ?string $label, string $q): array
    {
        return ['label' => $label, 'q' => $q];
    }
}
