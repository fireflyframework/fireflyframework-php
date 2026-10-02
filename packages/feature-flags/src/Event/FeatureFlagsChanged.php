<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Event;

/**
 * The effective flag set changed (spec §4.9, CONTRACT.md "Telemetry and events"): published once per change by the
 * process that observed it, never for a recomposition that changes nothing.
 *
 * `changedKeys` are the keys whose effective flag differs, sorted as text: a key that appeared or disappeared, any
 * field of its composed definition (fields flagd does not read included; `true` is not `1`, `1` is not `1.0`), an
 * evaluator its targeting references directly or through another evaluator, or a document metadata entry it inherits.
 * Where a definition comes from (its origin and what it shadows) is not part of it. The set compared is the one the
 * application evaluates: with test overrides active, a source change to a key an override shadows is not announced
 * (clearing the override announces it).
 *
 * `origin` is the highest-precedence source whose document moved (else the highest-precedence loaded source),
 * `startup` for the first composition, or `test-overrides` when test support changed the effective set.
 */
final readonly class FeatureFlagsChanged
{
    /**
     * @param  list<string>  $changedKeys
     */
    public function __construct(
        public array $changedKeys,
        public string $origin,
    ) {}
}
