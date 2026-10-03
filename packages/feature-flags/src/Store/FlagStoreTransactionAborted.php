<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use RuntimeException;
use Throwable;

final class FlagStoreTransactionAborted extends RuntimeException
{
    public function __construct(Throwable $cause)
    {
        parent::__construct('The database aborted this transaction; roll it back before starting new work.', 0, $cause);
    }
}
