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
 * The rule itself is unchanged from the one EnvEndpoint has always enforced (fail-safe invariant: /env values
 * masked) — a key matching password|secret|token|key|credential|passwd, case-insensitively, anywhere in the
 * key, is replaced with ******.
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
 */
final class SensitiveValueMasker
{
    public const string MASK = '******';

    private const string SENSITIVE = '/password|secret|token|key|credential|passwd/i';

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
