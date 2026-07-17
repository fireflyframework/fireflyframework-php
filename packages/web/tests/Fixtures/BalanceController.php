<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
#[RequestMapping('/balances')]
final class BalanceController
{
    /** @return array<string,mixed> */
    #[GetMapping('/{id}')]
    public function show(#[PathVariable] int $id): array
    {
        if ($id === 404) {
            throw new ResourceNotFoundException("Balance {$id} not found");
        }

        return ['id' => $id, 'amount' => '100.00'];
    }

    /** @return array<string,mixed> */
    #[PostMapping(status: 201)]
    public function open(#[Valid] #[RequestBody] CreateAccountRequest $body): array
    {
        return ['iban' => $body->iban, 'owner' => $body->owner];
    }
}
