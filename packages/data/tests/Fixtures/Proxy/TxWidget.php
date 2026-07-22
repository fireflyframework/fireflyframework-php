<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Proxy;

use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * A proxy-generation fixture: one transactional method (increment) alongside non-transactional methods and a
 * readonly promoted ctor property (exercises the state copy in Task 7). NOT `final` — the proxy extends it.
 */
class TxWidget
{
    private int $counter = 0;

    public function __construct(private readonly string $config) {}

    public function boot(): void
    {
        $this->counter = 1;
    }

    public function bump(): void
    {
        $this->counter++;
    }

    #[Transactional]
    public function increment(int $by = 1): int
    {
        $this->counter += $by;

        return $this->counter;
    }

    public function counter(): int
    {
        return $this->counter;
    }

    public function config(): string
    {
        return $this->config;
    }
}
