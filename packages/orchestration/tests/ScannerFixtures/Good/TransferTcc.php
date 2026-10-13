<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Good;

use Firefly\Orchestration\Tcc\Attributes\OnTccComplete;
use Firefly\Orchestration\Tcc\Attributes\Tcc;
use Firefly\Orchestration\Tcc\Engine\TccResult;

#[Tcc(name: 'transfer', maxRetries: 1, backoffMs: 1)]
final class TransferTcc
{
    #[OnTccComplete]
    public function done(TccResult $result): void {}
}
