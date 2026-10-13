<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

/** The expectedVersion an append may name besides a concrete version. */
final class ExpectedVersion
{
    /** Append at the stream's head, whatever it is: no concurrency check (imports, single writers). */
    public const int ANY = -1;

    /** The stream must not exist yet. */
    public const int NO_STREAM = 0;
}
