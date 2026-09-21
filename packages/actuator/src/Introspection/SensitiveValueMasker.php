<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

/**
 * THE masking rule the introspection surface shares — one regex, one replacement, one recursion, used by
 * every endpoint that renders configuration back to a caller (/env and /configprops today).
 *
 * It lives in its own class rather than being copied because a second copy is how a masking rule rots: the
 * moment /configprops grew its own `password|secret|token` regex, the two lists would drift on the very next
 * key somebody thought to add ("private_key", "dsn"), and the endpoint that missed the addition would leak.
 * The rule is the one EnvEndpoint has always enforced (fail-safe invariant: /env values masked), grown by two
 * words after the audit below — a key matching password|secret|token|key|credential|passwd|authorization|
 * headers, case-insensitively, anywhere in the key, is replaced with ******.
 *
 * ARRAY-VALUED SECRETS. The rule EnvEndpoint originally implemented tested the key ONLY on the branch where
 * the value was a scalar:
 *
 *     if (is_array($value)) { $masked[$key] = $this->mask($value); continue; }   // <- recursed, never tested
 *     $masked[$key] = preg_match(SENSITIVE, $key) ? MASK : $value;
 *
 * so a sensitive key holding an ARRAY was never masked — it was descended into, and each leaf was then judged
 * on its OWN key. `firefly.security.jwt.keys => ['active' => 'PRIVATE...', 'previous' => '...']` therefore
 * rendered both private keys in full: `keys` matched the regex but was an array, `active` and `previous` did
 * not match anything. Every real-world shape of a secret — a keyring, a credentials pair, a per-tenant token
 * map — is exactly that shape, so the bypass covered the cases that mattered most. The order is inverted
 * here: the KEY decides first, and a sensitive key masks its whole subtree regardless of the value's type;
 * only a key that is NOT sensitive is descended into.
 *
 * Masking a sensitive array as the scalar ****** (rather than as a same-shaped array of ******) is
 * deliberate: the shape of a secret is itself information — how many keys are in the keyring, which tenants
 * have tokens — and a caller who may not see the values has no business counting them either.
 *
 * HEADERS AND AUTHORIZATION. The second audit finding was a key the framework itself introduced to carry a
 * credential: `firefly.observability.tracing.otlp.headers` documents `authorization=Bearer …` and
 * `x-honeycomb-team=…` as its intended contents, and none of the six original words appear in `headers`, so
 * /env rendered the vendor credential verbatim — one `FIREFLY_ACTUATOR_EXPOSE=*` away in any staging
 * environment. The list therefore names `headers` and `authorization`: a bag of headers is where an outbound
 * client's credential travels, whatever the header is called (a vendor's `x-honeycomb-team` matches nothing
 * on its own, which is exactly why the BAG's key must decide and not the leaves — the map form of the same
 * key, `['x-honeycomb-team' => '…']`, would otherwise be descended into and leak), and a key named
 * `authorization` holds a credential by definition, whatever the value looks like.
 *
 * The match is the PLURAL, `headers`, as a substring like every other word in the list (`request_headers`,
 * `defaultHeaders`, `include-headers` all match; the reviewer's `\bheaders?\b` would have skipped the first
 * two, because `_` and `H` sit on the word side of a boundary). The singular is deliberately NOT in the list:
 * the same predicate names the data browser's sensitive columns (DataColumn, DataSchemaFactory), and
 * `header_image`, `page_header`, `header_text` are what a CMS table calls its layout fields — masking them,
 * excluding them from search and refusing them as update targets would cost real usability for no
 * credential protected. The cost that IS accepted: `firefly.security.headers` (the response-header filter's
 * `enabled`/`hsts`/`csp` block) now renders as ****** on /env and the admin's environment page, and a
 * `headers` column on a webhook or request-log table is treated as a secret in the data browser. Both are
 * the right side of the trade — the first holds nothing an operator cannot read off any response the
 * application sends, the second is precisely where a captured `Authorization` header ends up.
 */
final class SensitiveValueMasker
{
    public const string MASK = '******';

    private const string SENSITIVE = '/password|secret|token|key|credential|passwd|authorization|headers/i';

    /**
     * The key type is preserved through the template so a caller that hands in an `array<string, mixed>`
     * gets an `array<string, mixed>` back without a suppressing @var at the call site.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $values
     * @return array<TKey, mixed>
     */
    public static function mask(array $values): array
    {
        $masked = [];
        foreach ($values as $key => $value) {
            if (self::isSensitive($key)) {
                $masked[$key] = self::MASK;

                continue;
            }

            $masked[$key] = is_array($value) ? self::mask($value) : $value;
        }

        return $masked;
    }

    /**
     * Integer keys are stringified before matching rather than skipped: a list under a non-sensitive key
     * carries indices 0, 1, 2, which can never match the pattern, so the cast costs nothing and keeps the
     * predicate total over array-key — no separate "is this a list?" branch that a future edit could forget.
     */
    public static function isSensitive(int|string $key): bool
    {
        return preg_match(self::SENSITIVE, (string) $key) === 1;
    }
}
