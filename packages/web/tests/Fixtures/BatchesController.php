<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/** Answers with each line's reference, read through the TransferLine type — a raw sub-array would TypeError here. */
#[RestController]
#[RequestMapping('/batches')]
final class BatchesController
{
    /** @return array<string, list<string>> */
    #[PostMapping(status: 201)]
    public function submit(#[Valid] #[RequestBody] BatchRequest $body): array
    {
        return [
            // @phpstan-ignore argument.type (the closure's TransferLine type IS the assertion: a raw sub-array TypeErrors here)
            'references' => array_values(array_map(static fn (TransferLine $line): string => $line->reference, $body->lines)),
        ];
    }
}
