<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** The oauth2_authorizations row. Mapped by EloquentOAuth2AuthorizationService. */
final class OAuth2AuthorizationModel extends Model
{
    protected $table = OAuth2ServerSchema::AUTHORIZATIONS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
