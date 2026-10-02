<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Source;

use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;

/**
 * One layer of flags (spec §4.5). Sources are #[Component]s; FlagRegistry collects every enabled one through
 * Container::getAll(FlagSource::class) and orders them by precedence (config 100 < file 200 < http 300 <
 * store 400). A source does no I/O in its constructor — every one is resolved eagerly at boot.
 *
 * A source keeps no state between loads: the registry remembers the revision of the last snapshot it accepted and
 * hands it back as $knownRevision. A load that throws yields no revision, so the registry keeps its last good
 * document and the same broken input is read (and refused) again on the next check.
 */
interface FlagSource
{
    public const string CONFIG = 'config';

    public const string FILE = 'file';

    public const string HTTP = 'http';

    public const string STORE = 'store';

    public function name(): string;

    /** Higher overrides lower, per flag key. */
    public function precedence(): int;

    /** Seconds between two checks of this source; 0 means "check on every refresh" (in-process data). */
    public function refreshInterval(): float;

    /** Whether failing before this source EVER loaded refuses the boot (config and file: spec §4.5). */
    public function failsStartup(): bool;

    /** The revision worth showing an operator (null hides an internal one, such as config's hash). */
    public function reportedRevision(?string $revision): ?string;

    /**
     * Loads the document when its revision differs from $knownRevision. Returns null ONLY when $knownRevision
     * is not null and nothing changed; load(null) always returns a snapshot or throws.
     *
     * @throws InvalidFlagDefinition the document breaks a contract rule (rejected as a whole)
     * @throws FlagSourceUnavailable the document cannot be read right now
     */
    public function load(?string $knownRevision): ?SourceSnapshot;
}
