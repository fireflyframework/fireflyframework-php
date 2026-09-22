<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** The oauth2_registered_clients row. Mapped to and from RegisteredClient by EloquentRegisteredClientRepository. */
final class RegisteredClientModel extends Model
{
    protected $table = OAuth2ServerSchema::CLIENTS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
