<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Listeners;

/** The application event the listener fixture hears; carries the title so the log can tell publishes apart. */
final readonly class NoteSaved
{
    public function __construct(public string $title) {}
}
