<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Users;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The documented schema, as a model: `email` (the username), `password` (the ENCODED string, `{id}`-prefixed),
 * `enabled` and `locked` booleans, `authorities` a JSON list — and two relations for the `relation.attribute`
 * form of the authorities setting: `roles()`, a roles table through a pivot, and `primaryRole()`, one role by
 * `role_id`. Deliberately NOT a Firefly entity — the driver reads any Eloquent model.
 */
final class Account extends Model
{
    protected $table = 'accounts';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['authorities' => 'array', 'enabled' => 'bool', 'locked' => 'bool'];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'account_role', 'account_id', 'role_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function primaryRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
