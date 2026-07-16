<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

/**
 * A SEPARATE event class from DedupeEvent (which Repo listens for) — this fixtures directory scans
 * ALL its classes together into one manifest, and RepoConfig's beans are eager Scope::Singleton, so
 * they register their own DedupeEvent listeners as soon as the shared pipeline boots. Using a
 * distinct event here keeps DedupeKeyTest's getListeners() counts isolated per scenario.
 */
final readonly class VaryEvent
{
    public function __construct(public string $tag) {}
}
