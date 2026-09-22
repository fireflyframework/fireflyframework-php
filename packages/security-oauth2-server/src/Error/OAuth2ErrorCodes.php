<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Error;

/** The error codes of RFC 6749 §4.1.2.1 / §5.2, RFC 6750 §3.1, RFC 7591 §3.2.2 and OpenID Connect Core §3.1.2.6. */
final class OAuth2ErrorCodes
{
    public const string INVALID_REQUEST = 'invalid_request';

    public const string INVALID_CLIENT = 'invalid_client';

    public const string INVALID_GRANT = 'invalid_grant';

    public const string UNAUTHORIZED_CLIENT = 'unauthorized_client';

    public const string UNSUPPORTED_GRANT_TYPE = 'unsupported_grant_type';

    public const string INVALID_SCOPE = 'invalid_scope';

    public const string UNSUPPORTED_RESPONSE_TYPE = 'unsupported_response_type';

    public const string ACCESS_DENIED = 'access_denied';

    public const string SERVER_ERROR = 'server_error';

    public const string TEMPORARILY_UNAVAILABLE = 'temporarily_unavailable';

    public const string INVALID_TOKEN = 'invalid_token';

    public const string INSUFFICIENT_SCOPE = 'insufficient_scope';

    public const string LOGIN_REQUIRED = 'login_required';

    public const string CONSENT_REQUIRED = 'consent_required';

    public const string INVALID_REDIRECT_URI = 'invalid_redirect_uri';

    public const string INVALID_CLIENT_METADATA = 'invalid_client_metadata';

    public const string UNSUPPORTED_TOKEN_TYPE = 'unsupported_token_type';
}
