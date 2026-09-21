<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Users;

use Illuminate\Database\Eloquent\Model;

/** A roles table — `name` is the authority — reached from Account through `roles()`, for `authorities: roles.name`. */
final class Role extends Model
{
    protected $table = 'roles';

    public $timestamps = false;

    protected $guarded = [];
}
