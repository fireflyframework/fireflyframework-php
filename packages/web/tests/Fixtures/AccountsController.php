<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestHeader;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
#[RequestMapping('/accounts')]
final class AccountsController
{
    /** @return array<string,mixed> */
    #[GetMapping('/{id}', name: 'accounts.show')]
    public function show(
        #[PathVariable] int $id,
        #[QueryParam(default: 'summary')] string $view,
        #[RequestHeader('X-Trace')] ?string $trace = null,
    ): array {
        return ['id' => $id, 'view' => $view, 'trace' => $trace];
    }

    /** @return array<string,mixed> */
    #[PostMapping(status: 201)]
    public function create(#[Valid] #[RequestBody] CreateAccountRequest $body): array
    {
        return ['iban' => $body->iban, 'owner' => $body->owner];
    }
}
