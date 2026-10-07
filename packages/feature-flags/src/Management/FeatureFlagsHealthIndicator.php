<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Management;

use Closure;
use Firefly\Actuator\Health\ConditionalHealthIndicator;
use Firefly\Actuator\Health\Health;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Container as FireflyContainer;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Registry\FlagRegistry;

/**
 * The `featureflags` health component (HealthContributorRegistrar names it after the class). DOWN only while an
 * enabled source has never loaded — a source serving its last good document after a failure is STALE, which is
 * a detail, not an outage: the flags still evaluate. Absent (not UNKNOWN) when there is no Firefly registry: the
 * subsystem is off, or the application brought its own provider.
 */
#[Component]
final class FeatureFlagsHealthIndicator implements ConditionalHealthIndicator
{
    /** @var Closure(): string */
    private readonly Closure $today;

    /** @param (Closure(): string)|null $today The current UTC date, Y-m-d. */
    public function __construct(private readonly FireflyContainer $beans, ?Closure $today = null)
    {
        $this->today = $today ?? static fn (): string => gmdate('Y-m-d');
    }

    public function available(): bool
    {
        return $this->registry() !== null;
    }

    public function health(): Health
    {
        $registry = $this->registry();
        if ($registry === null) {
            return Health::unknown();
        }

        $composition = $registry->composition(false);
        $sources = [];
        $down = false;
        foreach ($registry->states() as $state) {
            $sources[$state->name] = [
                'status' => $state->status(),
                'flags' => $state->flags,
                'lastRefresh' => $state->lastRefresh,
                'error' => $state->error,
            ];
            $down = $down || ! $state->loaded;
        }

        $details = [
            'sources' => $sources,
            'flags' => count($composition->flags),
            'expired' => FlagDefinitions::expiredKeys($composition->document()->flags, ($this->today)()),
        ];

        return $down ? Health::down($details) : Health::up($details);
    }

    private function registry(): ?FlagRegistry
    {
        $registry = $this->beans->has(FlagRegistry::class) ? $this->beans->get(FlagRegistry::class) : null;

        return $registry instanceof FlagRegistry ? $registry : null;
    }
}
