<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * A SECOND bound #[ConfigProperties] DTO, which is what makes the bound listing's tiebreak observable.
 *
 * The Config properties listing draws one row per PROPERTY across every DTO, so neither of the columns a
 * reader can see identifies a row: `class` names as many rows as the DTO has properties, and `key` names
 * one row per DTO that declares that property — `enabled`, `store` and `timeout` are declared by half the
 * framework's own DTOs. A single bound fixture cannot show that, because with one class every `key` is
 * unique by accident.
 *
 * SO THE VALUES ARE CHOSEN TO TIE. `$trailEnabled` resolves to `true` and so does BillingProperties'
 * `$dunningEnabled`, which means ordering by Value has to fall through to the tiebreak for exactly those
 * two rows — and the two candidate tiebreaks disagree about them: the property name puts `dunningEnabled`
 * first, the row's identity puts `Audit…::trailEnabled` first, because `AuditProperties` precedes
 * `BillingProperties`. AdminTableConfigPropsPagerTest asserts the second answer.
 *
 * Nothing here is named like a secret, for the reason BillingProperties spells out: the endpoint masks
 * resolved values through SensitiveValueMasker, and a masked fixture asserts the masker.
 */
#[ConfigProperties(prefix: 'audit')]
final readonly class AuditProperties
{
    public function __construct(
        public string $sink = 'database',
        public int $retentionDays = 90,
        public bool $trailEnabled = true,
    ) {}
}
