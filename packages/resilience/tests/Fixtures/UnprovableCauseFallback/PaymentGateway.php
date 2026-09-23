<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\UnprovableCauseFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;
use Throwable;

/**
 * The three shapes the position proof deliberately ABSTAINS from, kept together because a refusal is only
 * worth having while it never fires on code that works, and each of these is a place where reflection would
 * have to guess:
 *
 *   - `mixedArgument()` is declared `mixed`, which holds an exception object as happily as anything else, so
 *     a recovery taking the cause first may be exactly what its author wired up by hand;
 *   - `variadic()` has no fixed argument count — `variadic()` and `variadic('a', 'b')` are the same
 *     signature — so no position in the recovery is provably the wrong one;
 *   - `union()` puts a union type in the appended slot, which is not a single named type: the cause is not
 *     appended (the conservative answer the flag has always given) and the call still fits.
 *
 * All three compile. Refusing any of them would hard-fail `firefly:cache` on a signature that runs.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    #[Fallback(method: 'mixedQueued')]
    public function mixedArgument(mixed $payload): string
    {
        return 'charged';
    }

    public function mixedQueued(Throwable $cause): string
    {
        return 'queued';
    }

    #[Retry('payments')]
    #[Fallback(method: 'variadicQueued')]
    public function variadic(string ...$accounts): string
    {
        return 'charged';
    }

    public function variadicQueued(Throwable $cause): string
    {
        return 'queued';
    }

    #[Retry('payments')]
    #[Fallback(method: 'unionQueued')]
    public function union(string $account): string
    {
        return $account;
    }

    public function unionQueued(string $account, Throwable|string|null $cause = null): string
    {
        return 'queued';
    }
}
