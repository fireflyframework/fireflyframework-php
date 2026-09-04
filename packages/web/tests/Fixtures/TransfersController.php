<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * An end-to-end route whose request DTO contains another DTO — the exact shape that produced an HTTP 500
 * from a perfectly valid request. Used by the uncached-boot test to prove the whole pipeline (scan ->
 * validate -> hydrate -> render) behaves, rather than only the resolver in isolation.
 */
#[RestController]
#[RequestMapping('/transfers')]
final class TransfersController
{
    /** @return array<string,mixed> */
    #[PostMapping(status: 201)]
    public function transfer(#[Valid] #[RequestBody] MoneyTransferRequest $body): array
    {
        return [
            'amount' => $body->amount,
            'postcode' => $body->beneficiary->postcode,
            'lines' => count($body->lines),
        ];
    }
}
