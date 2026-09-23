<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Actions returning values JsonMessageConverter writes through toArray().
 */
#[RestController]
#[RequestMapping('/arrayable')]
final class ArrayableController
{
    #[GetMapping('/tally')]
    public function tally(): Tally
    {
        return new Tally;
    }

    #[GetMapping('/counters')]
    public function counters(): Counters
    {
        return new Counters;
    }

    #[GetMapping('/snapshot')]
    public function snapshot(): Snapshot
    {
        return new Snapshot;
    }

    #[GetMapping('/dual')]
    public function dual(): Dual
    {
        return new Dual;
    }

    #[GetMapping('/crate')]
    public function crate(): CrateEntity
    {
        return new CrateEntity;
    }

    #[GetMapping('/badge')]
    public function badge(): BadgeEntity
    {
        return new BadgeEntity;
    }

    #[GetMapping('/loose')]
    public function loose(): LooseEntity
    {
        return new LooseEntity;
    }
}
