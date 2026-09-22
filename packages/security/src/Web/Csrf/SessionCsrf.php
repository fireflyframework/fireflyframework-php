<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Csrf;

use Firefly\Kernel\Exception\Security\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;

/**
 * Laravel's session-token CSRF check, as the ONE call every session-backed verification makes: CsrfFilter on
 * a request that has a started session, and the login and logout filters BEFORE anything else — a login CSRF
 * (an attacker signing a victim into the attacker's account) is a real attack, so their check cannot depend
 * on the double-submit CsrfFilter being enabled or on a route middleware group the filters run ahead of.
 *
 * THE TOKEN IS READ WHERE LARAVEL'S OWN PreventRequestForgery READS IT, IN THE SAME ORDER: the `_token` field
 * a form carries, the `X-CSRF-TOKEN` header a script sends, and — the source a session-backed SPA actually
 * uses — the `X-XSRF-TOKEN` header. That header is the XSRF-TOKEN cookie echoed back verbatim: Laravel sets
 * the cookie to the session token, EncryptCookies encrypts it on the way out (with the per-cookie-name prefix
 * in front), and the client (Axios by default) sends the ciphertext unchanged, so it is decrypted through the
 * application Encrypter and the prefix removed before the constant-time comparison. A value that does not
 * decrypt is a mismatch, never an exception of its own: fail-closed, as Laravel is. Without that third source
 * CsrfFilter — global, at -80, ahead of the `web` group where Laravel's middleware would have accepted the
 * same request — refused every standard Laravel SPA client with a 403 the moment session security was on.
 * An empty field or header falls through to the next source, as Laravel's does; anything else that is not
 * exactly the session token is a 403.
 *
 * WHY THE ENCRYPTER IS RESOLVED ON USE AND NOT INJECTED. This is a dependency of CsrfFilter, an eager
 * singleton the EagerSingletonsPass builds at EVERY boot — `php artisan key:generate` on a fresh project
 * included, when APP_KEY is still empty and resolving the Encrypter throws MissingAppKeyException. Laravel
 * keeps its own middleware per-request for exactly that reason; here the Encrypter is asked of the container
 * on the first X-XSRF-TOKEN that has to be decrypted, never at construction, and a form or script that sends
 * `_token` or `X-CSRF-TOKEN` never needs it at all.
 */
final class SessionCsrf
{
    private const COOKIE = 'XSRF-TOKEN';

    public function __construct(private readonly Container $container) {}

    public function verify(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new AuthorizationException('CSRF token mismatch.');
        }

        $expected = $request->session()->token();
        $presented = $this->tokenFrom($request);

        if ($expected === '' || $presented === null || $presented === '' || ! hash_equals($expected, $presented)) {
            throw new AuthorizationException('CSRF token mismatch.');
        }
    }

    /**
     * PreventRequestForgery::getTokenFromRequest(), source for source: the field, the plain header, then the
     * encrypted cookie echo — null when none is present or the echo does not decrypt.
     */
    private function tokenFrom(Request $request): ?string
    {
        /** @var mixed $field */
        $field = $request->input('_token');
        if (is_string($field) && $field !== '') {
            return $field;
        }

        $header = $request->header('X-CSRF-TOKEN');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $echo = $request->header('X-XSRF-TOKEN');
        if (! is_string($echo) || $echo === '') {
            return null;
        }

        /** @var Encrypter $encrypter */
        $encrypter = $this->container->make(Encrypter::class);

        try {
            /** @var mixed $decrypted */
            $decrypted = $encrypter->decrypt($echo, EncryptCookies::serialized(self::COOKIE));
        } catch (DecryptException) {
            return null;
        }

        return is_string($decrypted) ? CookieValuePrefix::remove($decrypted) : null;
    }
}
