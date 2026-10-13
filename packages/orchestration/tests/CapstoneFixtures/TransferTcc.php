<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Orchestration\Tcc\Attributes\Tcc;

#[Tcc(name: 'transfer', maxRetries: 0, backoffMs: 1)]
final class TransferTcc {}
