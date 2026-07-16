<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\CacheUser;

use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\CachePort;
use Firefly\Container\Attributes\Service;

#[Service]
final class RedisCacheAdapter implements CachePort {}
