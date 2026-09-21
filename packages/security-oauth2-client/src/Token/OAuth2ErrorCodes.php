<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

/** The RFC 6749 error codes, plus the ones the client mints for its own refusals (Spring's OAuth2ErrorCodes and the client's literals). */
final class OAuth2ErrorCodes
{
    public const string INVALID_REQUEST = 'invalid_request';

    public const string UNAUTHORIZED_CLIENT = 'unauthorized_client';

    public const string ACCESS_DENIED = 'access_denied';

    public const string UNSUPPORTED_RESPONSE_TYPE = 'unsupported_response_type';

    public const string INVALID_SCOPE = 'invalid_scope';

    public const string SERVER_ERROR = 'server_error';

    public const string TEMPORARILY_UNAVAILABLE = 'temporarily_unavailable';

    public const string INVALID_CLIENT = 'invalid_client';

    public const string INVALID_GRANT = 'invalid_grant';

    public const string UNSUPPORTED_GRANT_TYPE = 'unsupported_grant_type';

    public const string INVALID_TOKEN_RESPONSE = 'invalid_token_response';

    public const string INVALID_STATE_PARAMETER = 'invalid_state_parameter';

    public const string AUTHORIZATION_REQUEST_NOT_FOUND = 'authorization_request_not_found';

    public const string CLIENT_REGISTRATION_NOT_FOUND = 'client_registration_not_found';

    public const string INVALID_REDIRECT_URI = 'invalid_redirect_uri';

    public const string INVALID_ID_TOKEN = 'invalid_id_token';

    public const string INVALID_NONCE = 'invalid_nonce';

    public const string INVALID_USER_INFO_RESPONSE = 'invalid_user_info_response';

    public const string MISSING_USER_INFO_URI = 'missing_user_info_uri';

    public const string MISSING_USER_NAME_ATTRIBUTE = 'missing_user_name_attribute';
}
