<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\CapstoneFixtures;

use Firefly\Scheduling\Attributes\Scheduled;

final class ReconcileJob
{
    public function __construct(private readonly SpyCounter $spy) {}

    #[Scheduled(fixedRate: '60s', lock: true)]
    public function run(): void
    {
        $this->spy->increment();
    }
}
