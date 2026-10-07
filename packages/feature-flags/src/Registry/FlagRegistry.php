<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Registry;

use Closure;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Event\FeatureFlagsChanged;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\FeatureFlags\Source\FlagSourceUnavailable;
use Firefly\FeatureFlags\Source\StoreFlagSource;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Composes the flag sources (spec §4.5) for a share-nothing runtime.
 *
 * Each source's last good document, revision and check time live in the application cache (CacheBook), so a
 * request re-checks a source only when its refresh-interval elapsed — whichever worker gets there first, under a
 * non-blocking lock, while every other worker serves the last good document. A forced refresh (a store write) loads
 * even while another worker holds the lock, so the writer sees its change at once.
 *
 * The composition is memoized in the process until the shortest refresh-interval of a polling source elapses, so a
 * long-lived Octane, queue or artisan process picks changes up on the same schedule a fresh FPM worker does. A polling
 * source at 0 is re-checked on every read; configuration never expires the memo (it cannot change within a process).
 * When the cache store fails, every source loads in-process and the flags keep evaluating: only the sharing and the
 * change events pause until the store answers again.
 *
 * The process that observes a change of the effective set publishes FeatureFlagsChanged once across all workers (a
 * Cache::add guard per transition of the shared record); that class says which keys it names and which origin. While
 * test overrides are active, the keys are those whose flag changed in this process with the overrides on top.
 *
 * Refusals: a configuration that fails always refuses the boot (FPM boots on every request); a flag file refuses it
 * only until it has once loaded into the shared cache; remote and store sources never do (they report DOWN until they
 * load). After that, a failing source keeps its last good document, reports STALE with the reason and logs one WARN
 * per distinct error, and a document that fails validation is rejected as a whole.
 */
final class FlagRegistry implements FlagDocumentSource
{
    /** ComposedFlag::$origin of a flag test overrides supply. */
    public const string TEST_LAYER = 'test-overrides';

    /** FeatureFlagsChanged::$origin when test overrides change the effective set. */
    public const string TEST_OVERRIDES_ORIGIN = 'test-overrides';

    /** FeatureFlagsChanged::$origin of the first composition the shared bookkeeping sees. */
    public const string STARTUP_ORIGIN = 'startup';

    /** Seconds within which two workers observing the same transition agree that only one announces it. */
    private const int CHANGE_WINDOW = 10;

    /** The shared record of the last composition (cache key `composed`, guarded by the lock `lock:composed`). */
    private const string RECORD = 'composed';

    /** Seconds the record lock outlives a holder that died while recording (recording takes milliseconds). */
    private const int RECORD_LOCK = 5;

    /** @var list<FlagSource> */
    private readonly array $sources;

    /** @var Closure(): float */
    private readonly Closure $clock;

    private ?Composition $shared = null;

    /** The shared composition with the test layer on top; null without test overrides. */
    private ?Composition $effective = null;

    /** The effective composition's document, built once per composition. */
    private ?FlagDocument $document = null;

    /** @var list<array{0: string, 1: FlagDocument}> the layers of the shared composition, lowest precedence first */
    private array $layers = [];

    /** @var list<array{0: string, 1: string}> those layers as source name and document JSON (unchanged = same composition) */
    private array $signature = [];

    private float $memoUntil = 0.0;

    private ?FlagDocument $testOverrides = null;

    /** @var array<string, SourceState> what this process last knew, for when the cache store knows nothing */
    private array $states = [];

    /** @var array<string, array{0: string, 1: FlagDocument}> per source, the last document JSON decoded and its document */
    private array $documents = [];

    /** The last composition could not be recorded (another worker held the record lock): record it at the next refresh. */
    private bool $recordPending = false;

    /**
     * @param  iterable<FlagSource>  $sources
     * @param  (Closure(): float)|null  $clock  Unix seconds
     *
     * @throws ConfigurationException two sources share a name, or a source takes a name the registry reserves
     */
    public function __construct(
        iterable $sources,
        private readonly CacheBook $book,
        private readonly ApplicationEventPublisher $events,
        private readonly LoggerInterface $logger = new NullLogger,
        ?Closure $clock = null,
    ) {
        $ordered = [...$sources];
        usort($ordered, static fn (FlagSource $a, FlagSource $b): int => $a->precedence() <=> $b->precedence());

        $names = [];
        foreach ($ordered as $source) {
            $name = $source->name();
            if (in_array($name, [self::TEST_LAYER, self::TEST_OVERRIDES_ORIGIN, self::STARTUP_ORIGIN, self::RECORD], true)) {
                throw new ConfigurationException(sprintf('A feature flag source cannot be named [%s]: the registry reserves %s, %s and %s for its own origins and %s for its shared record.', $name, self::TEST_LAYER, self::TEST_OVERRIDES_ORIGIN, self::STARTUP_ORIGIN, self::RECORD));
            }
            if (isset($names[$name])) {
                throw new ConfigurationException(sprintf('Two feature flag sources are named [%s]: every source needs a name of its own (it keys the source\'s shared state).', $name));
            }
            $names[$name] = true;
        }

        $this->sources = $ordered;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Boot: check what is due, then warn once a UTC day across every worker per expired flag.
     *
     * @throws InvalidFlagDefinition the configuration, or a flag file that never loaded, breaks a rule: names the
     *                               flag key, the reason and the source
     * @throws FlagSourceUnavailable a flag file that never loaded cannot be read
     */
    public function start(): void
    {
        $composition = $this->shared(refresh: true);
        $today = gmdate('Y-m-d', (int) floor(($this->clock)()));
        $definitions = array_map(static fn (ComposedFlag $flag) => $flag->definition, $composition->flags);

        foreach (FlagDefinitions::expiredKeys($definitions, $today) as $key) {
            $flag = $composition->flag($key);
            if ($flag !== null && $this->book->first('expired:'.$today.':'.$key, 86400)) {
                $this->logger->warning('Feature flag [{key}] expired on {expires} and is still defined by [{origin}]: remove it, or move its expires date.', [
                    'key' => $key,
                    'expires' => $flag->definition->expires(),
                    'origin' => $flag->origin,
                ]);
            }
        }
    }

    /** The effective document the provider evaluates: every source, test overrides on top. */
    public function document(): FlagDocument
    {
        $composition = $this->composition();

        return $this->document ??= $composition->document();
    }

    /**
     * @param  bool  $includeTestOverrides  false for what this process serves to others (sync endpoint, health)
     */
    public function composition(bool $includeTestOverrides = true): Composition
    {
        $shared = $this->shared();

        return $includeTestOverrides ? ($this->effective ?? $shared) : $shared;
    }

    /**
     * Check every source that is due (or forced), compose, and announce a change of the effective set.
     *
     * @param  bool  $force  re-check sources now, even before their refresh-interval elapsed
     * @param  string|null  $only  with $force: re-check only this source (a store write refreshes the store alone)
     * @return Composition without test overrides
     */
    public function refresh(bool $force = false, ?string $only = null): Composition
    {
        $now = ($this->clock)();
        $loaded = [];
        $layers = [];
        $moved = [];

        foreach ($this->sources as $source) {
            $name = $source->name();
            $before = $this->state($name);
            $forced = $force && ($only === null || $only === $name);
            $deferred = $source instanceof StoreFlagSource && $source->transactionActive();
            $after = ! $deferred && ($forced || $this->due($source, $before, $now)) ? $this->check($source, $before, $now, $forced) : $before;
            $document = $after === null ? null : $this->decoded($after);

            if (! $deferred && $after !== null && $after->loaded && $document === null) {
                // A cached entry this process cannot read (corrupt, or written in another shape): unknown, so reload it.
                $this->logger->warning('The cached document of feature flag source [{source}] cannot be read; reloading the source.', ['source' => $name]);
                $after = $this->check($source, null, $now, forced: true, known: false);
                $document = $this->decoded($after);
            }
            if ($after === null || $after->document === null || $document === null) {
                continue; // a source that never loaded contributes nothing
            }
            if ($before === null || $before->document !== $after->document) {
                $moved[] = $name;
            }
            $this->states[$name] = $after; // the newest document this process serves, loaded here or adopted from the cache
            $loaded[] = [$name, $after->document];
            $layers[] = [$name, $document];
        }

        $this->memoUntil = $now + $this->memoSeconds();

        $shared = $this->shared;
        if ($shared === null || $loaded !== $this->signature || $this->recordPending) {
            $overridden = $this->testOverrides === null ? null : $this->effective;
            $shared = Composer::compose($layers);
            $this->layers = $layers;
            $this->signature = $loaded;
            $this->shared = $shared;
            $this->effective = $this->testOverrides === null ? null : Composer::compose([...$layers, [self::TEST_LAYER, $this->testOverrides]]);
            $this->document = null;
            $this->observe($shared, $loaded, $this->origin($moved, $loaded), $overridden);
        }

        return $shared;
    }

    /**
     * Every registered source's state, in precedence order (a source that never ran yet reports DOWN).
     *
     * @return list<SourceState>
     */
    public function states(): array
    {
        return array_map(fn (FlagSource $source): SourceState => $this->state($source->name()) ?? SourceState::initial($source->name()), $this->sources);
    }

    /**
     * @return list<FlagSource>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    public function source(string $name): ?FlagSource
    {
        foreach ($this->sources as $source) {
            if ($source->name() === $name) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The test-support layer above every source (FeatureFlagOverrides), replaced as a whole; null removes it. It is
     * in-process only, never shared or served. Publishes FeatureFlagsChanged(keys, 'test-overrides') for the keys
     * whose effective flag it changes, and nothing when it changes none.
     */
    public function overrideForTests(?FlagDocument $overrides): void
    {
        $shared = $this->shared();
        $before = ($this->effective ?? $shared)->fingerprints();

        $this->testOverrides = $overrides;
        $this->effective = $overrides === null ? null : Composer::compose([...$this->layers, [self::TEST_LAYER, $overrides]]);
        $this->document = null;

        $changed = self::changedKeys($before, ($this->effective ?? $shared)->fingerprints());
        if ($changed !== []) {
            $this->publish(new FeatureFlagsChanged($changed, self::TEST_OVERRIDES_ORIGIN));
        }
    }

    public function testOverrides(): ?FlagDocument
    {
        return $this->testOverrides;
    }

    /** The shared composition, refreshed first when there is none yet, its memo expired, or $refresh asks. */
    private function shared(bool $refresh = false): Composition
    {
        $shared = $this->shared;
        if ($refresh || $shared === null || ($this->clock)() >= $this->memoUntil) {
            return $this->refresh();
        }

        return $shared;
    }

    /**
     * The cache's state of the source, else this process's own (a store outage, an evicted entry). A cached state that
     * never loaded does not displace this process's loaded one: it is an evicted entry written back by a worker that
     * could not load the source, and the last good document stays in use (and is written back at the next check).
     */
    private function state(string $name): ?SourceState
    {
        $cached = SourceState::fromArray($this->book->get('source:'.$name));
        $own = $this->states[$name] ?? null;
        if ($cached === null || $cached->name !== $name) {
            return $own;
        }

        return ! $cached->loaded && $own !== null && $own->loaded ? $own : $cached;
    }

    /**
     * The state's document, decoded once per JSON text; null when it has none or cannot be read: not JSON, or another
     * shape than the one SourceState::withLoaded() writes (its flag count disagrees).
     */
    private function decoded(SourceState $state): ?FlagDocument
    {
        $json = $state->loaded ? $state->document : null;
        if ($json === null) {
            return null;
        }

        $memo = $this->documents[$state->name] ?? null;
        if ($memo !== null && $memo[0] === $json) {
            return $memo[1];
        }

        try {
            $document = FlagDocument::fromJson($json);
        } catch (Throwable) {
            return null;
        }
        if (count($document->flags) !== $state->flags) {
            return null;
        }

        $this->documents[$state->name] = [$json, $document];

        return $document;
    }

    private function due(FlagSource $source, ?SourceState $state, float $now): bool
    {
        if ($state === null) {
            return true;
        }

        $interval = $source->refreshInterval();

        return $interval <= 0.0 || $now - $state->checkedAt >= $interval;
    }

    /**
     * Load the source and record the result in the cache and in this process. A polling source loads under its lock:
     * the holder re-reads the state once it has the lock (another worker may have checked it in between) and writes
     * the new state before letting the lock go, so the next holder reads this check instead of loading again.
     *
     * @param  bool  $known  false when the cached state cannot be read: load from scratch, ignoring it
     *
     * @throws InvalidFlagDefinition|FlagSourceUnavailable|Throwable as load()
     */
    private function check(FlagSource $source, ?SourceState $before, float $now, bool $forced, bool $known = true): SourceState
    {
        $name = $source->name();
        $interval = $source->refreshInterval();
        if ($interval <= 0.0) {
            return $this->settle($name, $before, $this->load($source, $before ?? SourceState::initial($name), $now), everyRefresh: true);
        }

        $release = $this->book->lock('lock:'.$name, (int) ceil($interval) + 1);
        if ($release === null) {
            if ($before !== null && ! $forced) {
                return $before; // another worker is checking it: serve the last good document meanwhile
            }

            return $this->settle($name, $before, $this->load($source, $before ?? SourceState::initial($name), $now));
        }

        try {
            $latest = $known ? ($this->state($name) ?? $before) : null;
            if (! $forced && $latest !== null && ! $this->due($source, $latest, $now)) {
                return $latest; // another worker checked it between this one reading its state and taking the lock
            }

            return $this->settle($name, $latest, $this->load($source, $latest ?? SourceState::initial($name), $now));
        } finally {
            $release();
        }
    }

    /**
     * Remember a check in this process and in the cache. A source checked on every refresh that found nothing new
     * writes nothing: its check time tells nothing, and a cache write per request would.
     */
    private function settle(string $name, ?SourceState $before, SourceState $after, bool $everyRefresh = false): SourceState
    {
        if ($everyRefresh && $before !== null && $after->sameAs($before)) {
            $this->states[$name] = $before;

            return $before;
        }

        $this->states[$name] = $after;
        $this->book->put('source:'.$name, $after->toArray());

        return $after;
    }

    /**
     * @throws InvalidFlagDefinition|FlagSourceUnavailable|Throwable only when the failure refuses the boot: always for
     *                                                               configuration, for a flag file until it ever loaded
     */
    private function load(FlagSource $source, SourceState $before, float $now): SourceState
    {
        $name = $source->name();

        try {
            $snapshot = $source->load($before->loaded ? $before->revision : null);

            return $snapshot === null ? $before->withUnchanged($now) : $before->withLoaded($snapshot->document, $snapshot->revision, $now);
        } catch (Throwable $failure) {
            if ($source->failsStartup() && ($name === FlagSource::CONFIG || ! $before->loaded)) {
                throw $failure instanceof InvalidFlagDefinition ? $failure->fromSource($name) : $failure;
            }

            $error = $failure->getMessage() !== '' ? $failure->getMessage() : $failure::class;
            if ($before->error !== $error) {
                $this->logger->warning('Feature flag source [{source}] failed{kept}: {error}', [
                    'source' => $name,
                    'kept' => $before->loaded ? '; its last good document stays in use' : '',
                    'error' => $error,
                ]);
            }

            return $before->withFailure($error, $now);
        }

    }

    /**
     * Record the shared composition for every worker, then announce what changed for this process.
     *
     * Without test overrides that is the shared transition, announced once across all workers. While test overrides
     * are active the application sees the effective set, so the keys are those whose EFFECTIVE flag changed in this
     * process: a source change to a key an override shadows is not announced (clearing the override announces it),
     * and an overridden key whose targeting references a changed evaluator is.
     *
     * @param  list<array{0: string, 1: string}>  $loaded  the composed layers as source name and document JSON
     * @param  Composition|null  $overridden  the effective composition before this refresh, while test overrides are active
     */
    private function observe(Composition $shared, array $loaded, string $origin, ?Composition $overridden): void
    {
        $transition = $this->record($shared, $loaded, hash('sha256', implode("\0", array_merge(...$loaded))));

        if ($overridden !== null && $this->effective !== null) {
            $changed = self::changedKeys($overridden->fingerprints(), $this->effective->fingerprints());
            if ($changed !== []) {
                $this->publish(new FeatureFlagsChanged($changed, $origin));
            }

            return;
        }

        if ($transition !== null && $this->book->first($transition['guard'], self::CHANGE_WINDOW)) {
            $this->publish(new FeatureFlagsChanged($transition['changed'], $transition['startup'] ? self::STARTUP_ORIGIN : $origin));
        }
    }

    /**
     * Compare the shared composition with the last one the shared bookkeeping recorded and record it: the transition
     * to announce (its keys, whether it is the first record, the guard key that lets one worker announce it), or null
     * when there is none, the store is down, or this process composed documents older than the shared ones.
     *
     * The record only moves forward. It is read, compared and written under the non-blocking lock `lock:composed`
     * (a worker that finds it held skips: the holder records, or this process at its next refresh, or the next
     * request), and right before writing a transition the shared source states are read again: a worker whose layers
     * no longer match them (it served a last good document while another worker loaded a newer one) neither writes
     * nor announces — it would announce a revert, and the next request the change again. The common case — the same
     * layers as the record — is answered from one read, without the lock.
     *
     * @param  list<array{0: string, 1: string}>  $loaded  the composed layers as source name and document JSON
     * @param  string  $layersHash  sha256 of $loaded: the same layers compose the same set, so the common case
     *                              (nothing moved since another worker recorded it) fingerprints no flag
     * @return array{changed: non-empty-list<string>, startup: bool, guard: string}|null
     */
    private function record(Composition $shared, array $loaded, string $layersHash): ?array
    {
        $previous = self::composedRecord($this->book->get(self::RECORD));
        if ($this->book->degraded()) {
            return null; // no shared memory of the previous set: every process would announce a "change"
        }
        if ($previous !== null && $previous['layers'] === $layersHash) {
            $this->recordPending = false;

            return null; // the common case, checked without the lock
        }

        $release = $this->book->lock('lock:'.self::RECORD, self::RECORD_LOCK);
        if ($release === null) {
            $this->recordPending = true; // another worker is recording: it, or this process's next refresh, records

            return null;
        }

        try {
            $this->recordPending = false;
            $previous = self::composedRecord($this->book->get(self::RECORD));
            if ($previous !== null && $previous['layers'] === $layersHash) {
                return null;
            }

            $keys = $shared->fingerprints();
            $fingerprint = hash('sha256', Json::canonical(Json::object($keys)));
            if ($previous !== null && $previous['fingerprint'] === $fingerprint) {
                $this->book->put(self::RECORD, [...$previous, 'layers' => $layersHash]); // other documents, the same effective flags

                return null;
            }
            if (! $this->current($loaded)) {
                return null;
            }

            // The generation makes each transition's guard key unique, so flipping back and forth is announced every time.
            $generation = ($previous['generation'] ?? 0) + 1;
            $this->book->put(self::RECORD, ['generation' => $generation, 'layers' => $layersHash, 'fingerprint' => $fingerprint, 'keys' => $keys]);

            $changed = self::changedKeys($previous['keys'] ?? [], $keys);

            return $changed === [] ? null : ['changed' => $changed, 'startup' => $previous === null, 'guard' => 'changed:'.$generation.':'.$fingerprint];
        } finally {
            $release();
        }
    }

    /**
     * Whether the source states hold, right now, the documents this process composed.
     *
     * @param  list<array{0: string, 1: string}>  $loaded
     */
    private function current(array $loaded): bool
    {
        $states = [];
        foreach ($this->sources as $source) {
            $state = $this->state($source->name());
            if ($state !== null && $state->loaded && $state->document !== null) {
                $states[] = [$source->name(), $state->document];
            }
        }

        return $states === $loaded;
    }

    /**
     * The highest-precedence source whose document moved, else the highest-precedence loaded source (the change
     * came from another process or deploy), else the highest-precedence source.
     *
     * @param  list<string>  $moved
     * @param  list<array{0: string, 1: string}>  $loaded
     */
    private function origin(array $moved, array $loaded): string
    {
        if ($moved !== []) {
            return $moved[count($moved) - 1];
        }
        if ($loaded !== []) {
            return $loaded[count($loaded) - 1][0];
        }

        return $this->sources === [] ? self::STARTUP_ORIGIN : $this->sources[count($this->sources) - 1]->name();
    }

    private function publish(FeatureFlagsChanged $event): void
    {
        try {
            $this->events->publish($event);
        } catch (Throwable $failure) {
            $this->logger->warning('A FeatureFlagsChanged listener failed ({error}); the flags it announced stay in force.', ['error' => $failure->getMessage()]);
        }
    }

    /** Seconds until a polling source may be due again: its interval (0 = every read); configuration never is. */
    private function memoSeconds(): float
    {
        $seconds = INF;
        foreach ($this->sources as $source) {
            if ($source->name() !== FlagSource::CONFIG) {
                $seconds = min($seconds, max(0.0, $source->refreshInterval()));
            }
        }

        return $seconds;
    }

    /**
     * The keys whose fingerprint differs between two Composition::fingerprints() maps, added and removed keys
     * included, sorted as text.
     *
     * @param  array<array-key, string>  $before
     * @param  array<array-key, string>  $after
     * @return list<string>
     */
    private static function changedKeys(array $before, array $after): array
    {
        $changed = [];
        foreach ([...array_keys($before), ...array_keys($after)] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[(string) $key] = (string) $key;
            }
        }
        $changed = array_values($changed);
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * The shared record observe() writes, or null for anything else.
     *
     * @return array{generation: int, layers: string, fingerprint: string, keys: array<array-key, string>}|null
     */
    private static function composedRecord(mixed $record): ?array
    {
        if (! is_array($record)
            || ! is_int($record['generation'] ?? null)
            || ! is_string($record['layers'] ?? null)
            || ! is_string($record['fingerprint'] ?? null)
            || ! is_array($record['keys'] ?? null)) {
            return null;
        }

        $keys = [];
        foreach ($record['keys'] as $key => $fingerprint) {
            if (! is_string($fingerprint)) {
                return null;
            }
            $keys[$key] = $fingerprint;
        }

        return ['generation' => $record['generation'], 'layers' => $record['layers'], 'fingerprint' => $record['fingerprint'], 'keys' => $keys];
    }
}
