<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Execution;

/** Where one delivery stands. `failed` is final until someone replays it. */
enum CallbackStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
