<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\InheritedPlain;

use Firefly\Container\Attributes\Service;

#[Service]
class ServiceChild extends Base {}
