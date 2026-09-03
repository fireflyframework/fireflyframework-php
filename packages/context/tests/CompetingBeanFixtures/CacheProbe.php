<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

use Firefly\Container\Attributes\Component;

/**
 * The single shared observation point for this fixture set: a real container-managed
 * #[Component] every other fixture here injects, so everything recorded below arrives through the
 * REAL ContainerRegistrar wiring rather than a hand-bound test double.
 *
 * Two same-typed #[Bean] methods are the whole point of this directory, so the recorder has to
 * distinguish the two products by NAME, not by class: every entry is prefixed with the bean's own
 * #[Bean] name ('memory' / 'redis'), which is exactly the axis the bugs under test collapse.
 */
#[Component]
final class CacheProbe
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function matching(string $prefix): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (string $event): bool => str_starts_with($event, $prefix),
        ));
    }
}
