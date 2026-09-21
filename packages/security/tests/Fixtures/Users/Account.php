<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Users;

use Illuminate\Database\Eloquent\Model;

/**
 * The documented schema, as a model: `email` (the username), `password` (the ENCODED string, `{id}`-prefixed),
 * `enabled` and `locked` booleans, `authorities` a JSON list. Deliberately NOT a Firefly entity — the driver
 * reads any Eloquent model.
 */
final class Account extends Model
{
    protected $table = 'accounts';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['authorities' => 'array', 'enabled' => 'bool', 'locked' => 'bool'];
}
