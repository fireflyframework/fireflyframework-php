<?php

declare(strict_types=1);

namespace Firefly\Security\Web\RememberMe;

use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\User\UserDetails;
use Firefly\Security\User\UserDetailsService;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spring's TokenBasedRememberMeServices: a cookie holding `username:expiry:signature` where the signature is
 * HMAC-SHA256 over `username:expiry:password-hash` with the configured key. Nothing is stored server-side.
 * Binding the PASSWORD HASH into the signature is what makes a password change invalidate every cookie
 * out there, and binding the expiry is what makes the cookie's own lifetime unforgeable. The username is
 * url-encoded inside the token so a `:` in it cannot split the fields; the whole token is base64url.
 *
 * autoLogin() answers null — the anonymous path — for every kind of bad cookie, and logs at debug why.
 * The user lookup happens only AFTER the token parses, and the signature is compared constant-time; a
 * cookie with an unknown username and a forged signature is refused by the signature alone once the user
 * is loaded, so the outcome for "unknown user" and "wrong signature" is the same null. The account flags
 * are checked LAST, after the signature: a disabled or locked account is refused even when its cookie is
 * genuine, which is what makes disabling an account take effect on the next request rather than at the
 * cookie's expiry.
 *
 * THE COOKIE IT SETS is HttpOnly (a script has no business reading a credential), SameSite=Lax (a top-level
 * navigation from another site carries it, which is what "come back and be signed in" means; a cross-site
 * POST does not), Secure whenever the request that set it was, and lives exactly as long as the signed
 * expiry — the browser and the signature agree on the lifetime, so neither can be stretched alone. The
 * cookie is set only when the principal is a UserDetails, because the signature needs the encoded password
 * that only a UserDetails carries: the AuthenticationManager hands the filters a token whose principal is
 * the loaded user with its hash intact (eraseCredentials() is the repository's concern, not the manager's).
 * logout() expires the same cookie: an empty value and an expiry in the past, on the same path.
 */
final class TokenBasedRememberMeServices implements RememberMeServices
{
    public function __construct(
        private readonly RememberMeSettings $settings,
        private readonly UserDetailsService $users,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function autoLogin(Request $request): ?Authentication
    {
        /** @var mixed $cookie */
        $cookie = $request->cookie($this->settings->cookieName);
        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        $parts = self::decode($cookie);
        if ($parts === null) {
            $this->logger?->debug('Remember-me cookie ignored: malformed.');

            return null;
        }
        [$username, $expiry, $signature] = $parts;

        if ($expiry < time()) {
            $this->logger?->debug('Remember-me cookie ignored: expired.', ['username' => $username]);

            return null;
        }

        try {
            $user = $this->users->loadUserByUsername($username);
        } catch (UsernameNotFoundException) {
            $this->logger?->debug('Remember-me cookie ignored: unknown user.');

            return null;
        }

        if (! hash_equals($this->sign($user->getUsername(), $expiry, $user->getPassword()), $signature)) {
            $this->logger?->debug('Remember-me cookie ignored: signature mismatch.', ['username' => $username]);

            return null;
        }

        if (! $user->isEnabled() || ! $user->isAccountNonLocked()) {
            $this->logger?->debug('Remember-me cookie ignored: account disabled or locked.', ['username' => $username]);

            return null;
        }

        return Authentication::authenticated($user->getUsername(), $user, $user->getAuthorities());
    }

    public function loginSuccess(Request $request, Response $response, Authentication $authentication): void
    {
        $asked = $this->settings->alwaysRemember
            || filter_var($request->input($this->settings->parameter), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
        /** @var mixed $principal */
        $principal = $authentication->getPrincipal();

        if (! $asked || ! $principal instanceof UserDetails) {
            return;
        }

        $expiry = time() + $this->settings->tokenValiditySeconds;
        $token = self::encode($principal->getUsername(), $expiry, $this->sign($principal->getUsername(), $expiry, $principal->getPassword()));

        $response->headers->setCookie(new Cookie($this->settings->cookieName, $token, $expiry, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    public function logout(Request $request, Response $response): void
    {
        $response->headers->setCookie(new Cookie($this->settings->cookieName, '', 1, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    /** The cookie value for a username, its expiry (unix seconds) and the hex signature: base64url, no padding. */
    public static function encode(string $username, int $expiry, string $signature): string
    {
        return rtrim(strtr(base64_encode(rawurlencode($username).':'.$expiry.':'.$signature), '+/', '-_'), '=');
    }

    /**
     * The three fields of a cookie value, or null when it is not one this class wrote: not base64, not
     * three colon-separated fields, an empty username, an expiry that is not a plain integer, or a signature
     * that is not 64 hex characters. Shape only — the signature and the expiry are judged by autoLogin().
     *
     * @return array{0: string, 1: int, 2: string}|null username, expiry, signature
     */
    public static function decode(string $cookie): ?array
    {
        $decoded = base64_decode(strtr($cookie, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(':', $decoded, 3);
        if (count($parts) !== 3 || $parts[0] === '' || preg_match('/^\d{1,12}$/', $parts[1]) !== 1 || preg_match('/^[0-9a-f]{64}$/', $parts[2]) !== 1) {
            return null;
        }

        return [rawurldecode($parts[0]), (int) $parts[1], $parts[2]];
    }

    private function sign(string $username, int $expiry, string $passwordHash): string
    {
        return hash_hmac('sha256', $username.':'.$expiry.':'.$passwordHash, $this->settings->key);
    }
}
