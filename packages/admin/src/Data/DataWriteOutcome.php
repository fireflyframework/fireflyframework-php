<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * What happened to a write, as four distinguishable outcomes rather than a bool.
 *
 * A bare `false` collapses four situations a person needs to tell apart, and the view's copy is different for
 * every one: `Refused` means the operator has to change configuration (or is asking for something the browser
 * will never do, like re-keying a row); `NotFound` means the row or the resource is gone and the page should
 * navigate away rather than offer a retry; `Failed` means the database said no and the operator should look
 * at the log; `Done` means show the new state. Rendering "delete failed" for all four sends an operator to
 * debug a database that is working perfectly because a config key is off.
 */
enum DataWriteOutcome: string
{
    case Done = 'done';
    case Refused = 'refused';
    case NotFound = 'not_found';
    case Failed = 'failed';
}
