<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Every projection the runner advances, by name. The auto-configuration fills it with the scanned #[Component]
 * projections; register() adds one more. A name is a checkpoint row and a lease, so two projections of one name are
 * refused rather than sharing a position.
 */
final class ProjectionRegistry
{
    /** @var array<string, Projection> */
    private array $projections = [];

    /** @param iterable<Projection> $projections */
    public function __construct(iterable $projections = [])
    {
        foreach ($projections as $projection) {
            $this->register($projection);
        }
    }

    public function register(Projection $projection): void
    {
        $name = $projection->name();
        if ($name === '') {
            throw new ConfigurationException('A projection ('.$projection::class.') must have a non-empty name(): it names its checkpoint and its lease.');
        }

        $existing = $this->projections[$name] ?? null;
        if ($existing !== null && $existing !== $projection) {
            throw new ConfigurationException("Two projections are named [{$name}]: ".$existing::class.' and '.$projection::class.'. A name is a checkpoint, so it must be unique.');
        }

        $this->projections[$name] = $projection;
        ksort($this->projections, SORT_STRING);
    }

    public function has(string $name): bool
    {
        return isset($this->projections[$name]);
    }

    public function get(string $name): Projection
    {
        return $this->projections[$name] ?? throw new EventSourcingException(
            "No projection is named [{$name}]; registered: ".($this->projections === [] ? 'none' : implode(', ', $this->names())).'.',
            'EVENT_SOURCING_UNKNOWN_PROJECTION',
            404,
        );
    }

    /** @return list<Projection> by name */
    public function all(): array
    {
        return array_values($this->projections);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (Projection $projection): string => $projection->name(), $this->all());
    }
}
