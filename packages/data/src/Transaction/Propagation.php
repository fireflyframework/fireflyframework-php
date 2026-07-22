<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use ValueError;

/**
 * Transaction propagation modes (Spring's seven, incl. NESTED which Laravel savepoints enable). Unbacked so the
 * cases read cleanly at the call site; fromName() reconstructs a case from its serialised ->name in the manifest.
 */
enum Propagation
{
    case REQUIRED;
    case REQUIRES_NEW;
    case NESTED;
    case SUPPORTS;
    case NOT_SUPPORTED;
    case MANDATORY;
    case NEVER;

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new ValueError("Unknown propagation [{$name}].");
    }
}
