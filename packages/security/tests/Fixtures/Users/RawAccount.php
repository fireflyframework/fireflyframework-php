<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Users;

use Illuminate\Database\Eloquent\Model;

/**
 * The same `accounts` table as Account, with NO casts: `authorities` comes back as the JSON string the
 * database holds, which is the shape the driver decodes for a model that never declared an `array` cast.
 */
final class RawAccount extends Model
{
    protected $table = 'accounts';

    public $timestamps = false;

    protected $guarded = [];
}
