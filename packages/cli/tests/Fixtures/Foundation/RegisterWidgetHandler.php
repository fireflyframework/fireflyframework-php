<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Cqrs\Attributes\CommandHandler;

/** Handles RegisterWidget by adding a Widget to the shared store; returns the resulting count. Mirrors the T3 App\
 *  DemoCommandHandler shape. */
#[CommandHandler]
final class RegisterWidgetHandler
{
    public function __construct(private readonly WidgetStore $store) {}

    public function handle(RegisterWidget $command): int
    {
        $this->store->add(new Widget($command->name));

        return $this->store->count();
    }
}
