<?php

declare(strict_types=1);

namespace Lumen\Application\Payout;

use Firefly\Kernel\Exception\Business\ConflictException;

/** The bank refused a payout step — a business conflict (409), never a server fault. */
class PayoutRejected extends ConflictException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'PAYOUT_REJECTED');
    }
}
