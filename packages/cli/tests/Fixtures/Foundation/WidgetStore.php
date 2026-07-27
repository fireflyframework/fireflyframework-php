<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Container\Attributes\Service;

/**
 * A #[Service] (singleton-scoped by default) shared by the CQRS command + query handlers: the command writes a
 * Widget into it, the query reads the count back — proving a command dispatched and a query observed its effect.
 */
#[Service]
final class WidgetStore
{
    /** @var list<Widget> */
    private array $widgets = [];

    public function add(Widget $widget): void
    {
        $this->widgets[] = $widget;
    }

    public function count(): int
    {
        return count($this->widgets);
    }
}
