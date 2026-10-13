<?php

declare(strict_types=1);

namespace App\Cards;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\FromStep;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;

#[Service]
#[Saga(name: 'greeting-card')]
final class GreetingCardSaga
{
    public const int PRICE_MINOR = 300;

    public function __construct(private readonly CardDesk $desk) {}

    #[SagaStep(id: 'reserve', compensate: 'release')]
    public function reserve(): string
    {
        return $this->desk->reserve();
    }

    public function release(string $card): void
    {
        $this->desk->release($card);
    }

    #[SagaStep(id: 'charge', dependsOn: ['reserve'], compensate: 'refund')]
    public function charge(): string
    {
        return $this->desk->charge(self::PRICE_MINOR);
    }

    public function refund(string $payment): void
    {
        $this->desk->refund($payment);
    }

    #[SagaStep(id: 'send', dependsOn: ['charge'])]
    public function send(#[FromStep('reserve')] string $card, #[Input('to')] string $to): string
    {
        return $this->desk->send($card, $to);
    }
}
