<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Consent;

use Illuminate\Contracts\Session\Session;

/**
 * The authorization request a consent page was rendered for, kept in the SESSION under a random state until the
 * form comes back — so the POST can only resume a request this very browser started (the CSRF token proves the
 * form, the state proves the request), and a stale or foreign state resumes nothing. One pending request per
 * session: a second authorization request replaces the first.
 */
final class PendingConsent
{
    public const string KEY = 'firefly.security.oauth2.server.consent';

    /**
     * @param  array<string,mixed>  $data
     */
    public static function store(Session $session, string $state, array $data): void
    {
        $session->put(self::KEY, ['state' => $state] + $data);
    }

    /**
     * The pending data when $state names it — removed as it is read — and null otherwise.
     *
     * @return array<string,mixed>|null
     */
    public static function consume(Session $session, string $state): ?array
    {
        /** @var mixed $pending */
        $pending = $session->get(self::KEY);
        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || $state === '' || ! hash_equals($pending['state'], $state)) {
            return null;
        }
        $session->forget(self::KEY);

        /** @var array<string,mixed> $pending */
        return $pending;
    }
}
