<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Closure;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\FeatureFlags\Source\SourceSnapshot;
use Throwable;

/**
 * A scriptable source: flags (shorthand allowed), shared evaluators and document metadata, a revision, an optional
 * failure, a load counter, and a hook that runs once inside the next load (another worker interleaving).
 */
final class StubFlagSource implements FlagSource
{
    public int $loads = 0;

    public ?Throwable $failure = null;

    public string $revision = 'r1';

    /** @var array<array-key, mixed> */
    public array $evaluators = [];

    /** @var array<array-key, mixed> */
    public array $metadata = [];

    /** @var (Closure(): void)|null */
    public ?Closure $beforeLoad = null;

    /**
     * @param  array<array-key, mixed>  $flags
     */
    public function __construct(
        private readonly string $name,
        private readonly int $precedence,
        private readonly float $interval,
        public array $flags = [],
        private readonly bool $failsStartup = false,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function precedence(): int
    {
        return $this->precedence;
    }

    public function refreshInterval(): float
    {
        return $this->interval;
    }

    public function failsStartup(): bool
    {
        return $this->failsStartup;
    }

    public function reportedRevision(?string $revision): ?string
    {
        return $revision;
    }

    public function load(?string $knownRevision): ?SourceSnapshot
    {
        $this->loads++;
        $hook = $this->beforeLoad;
        $this->beforeLoad = null;
        if ($hook !== null) {
            $hook();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($knownRevision === $this->revision) {
            return null;
        }

        return new SourceSnapshot(FlagDefinitions::parseDocument([
            'flags' => Json::object(FlagDefinitions::normalize($this->flags)),
            '$evaluators' => $this->evaluators,
            'metadata' => $this->metadata,
        ]), $this->revision);
    }
}
