<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Bad\HalfParticipant;

use Firefly\Orchestration\Tcc\Attributes\Tcc;

#[Tcc(name: 'half')]
final class HalfTcc {}
