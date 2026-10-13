<?php

declare(strict_types=1);

namespace App\Http;

use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class GreetingCardController
{
    public function __construct(private readonly SagaEngine $sagas) {}

    /** @return array{order: string, status: string} */
    #[PostMapping('/cards/{to}', status: 201)]
    public function send(#[PathVariable] string $to): array
    {
        $result = $this->sagas->execute('greeting-card', ['to' => $to]);

        if (! $result->success) {
            throw new ConflictException("The card for {$to} was not sent; every completed step was undone.", 'CARD_NOT_SENT', $result->error);
        }

        return ['order' => $result->correlationId, 'status' => $result->status->value];
    }
}
