<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Handler;

/**
 * Whether a discovered handler serves the write side (a command) or the read side (a query). A backed enum so it
 * serialises to a plain string in the compiled HandlerManifest and round-trips through HandlerDescriptor::fromArray.
 */
enum HandlerKind: string
{
    case Command = 'command';
    case Query = 'query';
}
