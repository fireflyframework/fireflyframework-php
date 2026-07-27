<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

/** A CQRS command carrying the widget name to register. Mirrors the T3 App\DemoCommand shape. */
final readonly class RegisterWidget
{
    public function __construct(public string $name) {}
}
