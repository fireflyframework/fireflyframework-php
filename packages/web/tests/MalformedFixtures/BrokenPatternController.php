<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\MalformedFixtures;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RestController;

/** A pattern with an unbalanced group: preg_match() would return false on every request. */
#[RestController]
final class BrokenPatternController
{
    #[GetMapping('/broken/{id}')]
    public function show(#[PathVariable(pattern: '[0-9+')] string $id): string
    {
        return $id;
    }
}
