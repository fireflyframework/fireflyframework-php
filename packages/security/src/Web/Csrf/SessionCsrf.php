<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Csrf;

use Firefly\Kernel\Exception\Security\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Laravel's session-token CSRF check, as one static call the login and logout filters make BEFORE anything
 * else — a login CSRF (an attacker signing a victim into the attacker's account) is a real attack, so the
 * check cannot depend on the double-submit CsrfFilter being enabled or on a route middleware group the
 * filters run ahead of. Accepts the `_token` field a form carries or the `X-CSRF-TOKEN` header a script
 * sends, compared constant-time against the session's token; anything else is a 403.
 */
final class SessionCsrf
{
    public static function verify(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new AuthorizationException('CSRF token mismatch.');
        }

        $expected = $request->session()->token();
        /** @var mixed $presented */
        $presented = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');

        if ($expected === '' || ! is_string($presented) || ! hash_equals($expected, $presented)) {
            throw new AuthorizationException('CSRF token mismatch.');
        }
    }
}
