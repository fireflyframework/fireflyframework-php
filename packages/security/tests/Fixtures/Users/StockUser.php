<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Users;

use Illuminate\Database\Eloquent\Model;

/**
 * Laravel's stock `users` table as the driver meets it — `id`, `name`, `email`, `password`, no casts, and
 * NO authorities, enabled or locked column — for the `authorities => ''` opt-out that lets the eloquent
 * driver read `App\Models\User` without a schema change. Its sibling Account is the documented full schema.
 */
final class StockUser extends Model
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
