<?php

declare(strict_types=1);

namespace Firefly\Observability\HttpExchanges;

/**
 * Masks header values before an exchange row can carry them.
 *
 * Header capture is opt-in (firefly.observability.httpexchanges.include-headers) precisely because an HTTP
 * exchange log that records headers verbatim is the canonical way one of these endpoints leaks credentials.
 * When it IS turned on, everything goes through here first.
 *
 * THE RULE IS EnvEndpoint'S, PLUS THE HEADER NAMES IT CANNOT SEE — and the "plus" is the whole point of this
 * class existing rather than the regex being inlined. EnvEndpoint masks
 * `/password|secret|token|key|credential|passwd/i` against CONFIG KEY names, where that alternation is
 * exhaustive in practice ('app.key', 'services.*.secret', 'database.password'). Applied unchanged to HTTP
 * HEADER names it has a hole big enough to drive the entire feature through: `Authorization` — the single most
 * credential-bearing header on the web, carrying Basic credentials and Bearer tokens verbatim — matches NONE of
 * those six alternatives. Neither does `Cookie`, which carries the session identifier, nor `Set-Cookie` on the
 * way back. Reusing the config rule verbatim would have shipped a masker that passes `Authorization: Bearer
 * <token>` straight into the buffer while looking, in review, exactly like the endpoint that is already trusted
 * to mask secrets.
 *
 * So: the EnvEndpoint alternation is kept as-is (an X-Api-Key or an X-Csrf-Token still matches on `key`/`token`,
 * and a future config-side addition stays meaningful here), and the three header-specific credential names are
 * added to it. Widening a mask is always safe — the failure mode is a masked header an operator wanted to see,
 * not a leaked one. Narrowing it never is.
 */
final class HeaderMasker
{
    public const MASK = '******';

    /**
     * EnvEndpoint::SENSITIVE verbatim, plus authorization/cookie (which covers set-cookie as a substring match).
     * `proxy-authorization` and `www-authenticate` are covered by the `authorization`/`authenticate`-adjacent
     * `authorization` alternative and by `credential` respectively; `x-amz-security-token` and friends fall out
     * of `token`/`secret`.
     */
    private const SENSITIVE = '/password|secret|token|key|credential|passwd|authorization|cookie|authenticate/i';

    /**
     * Flattens Symfony's header bag (name => list of values) into a single string per header, masking any header
     * whose NAME matches. Multi-valued headers are joined with ", " — the same folding RFC 9110 §5.3 permits —
     * because a dashboard cell renders a string, and because a header with two values is not more interesting
     * than a header with one.
     *
     * Null entries (Symfony models a header set to null as `[null]`) become empty strings rather than being
     * dropped, so "the header was present but empty" stays distinguishable from "the header was absent".
     *
     * @param  array<string, array<int, string|null>>  $headers
     * @return array<string, string>
     */
    public static function mask(array $headers): array
    {
        $masked = [];

        foreach ($headers as $name => $values) {
            $name = strtolower($name);

            if (preg_match(self::SENSITIVE, $name) === 1) {
                $masked[$name] = self::MASK;

                continue;
            }

            $masked[$name] = implode(', ', array_map(static fn (?string $value): string => $value ?? '', $values));
        }

        ksort($masked);

        return $masked;
    }
}
