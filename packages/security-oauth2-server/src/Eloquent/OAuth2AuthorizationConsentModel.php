<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** The oauth2_authorization_consents row (id = client|principal). Mapped by EloquentOAuth2AuthorizationConsentService. */
final class OAuth2AuthorizationConsentModel extends Model
{
    protected $table = OAuth2ServerSchema::CONSENTS;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
