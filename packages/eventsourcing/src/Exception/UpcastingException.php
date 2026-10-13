<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Exception;

/** An upcaster that does not move the event forward, or a chain that never ends. */
final class UpcastingException extends EventSourcingException
{
    public static function noProgress(string $type, int $version): self
    {
        return new self("An upcaster for [{$type}] version {$version} returned the same type and version; an upcaster must raise the version or change the type.", 'EVENT_UPCASTING');
    }

    public static function loop(string $type): self
    {
        return new self("Upcasting [{$type}] did not settle within 64 steps; two upcasters undo each other.", 'EVENT_UPCASTING');
    }
}
