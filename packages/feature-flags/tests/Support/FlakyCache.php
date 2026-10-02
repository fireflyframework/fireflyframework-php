<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use RuntimeException;

/** An array cache a test can take down and bring back: while $down, every call the registry makes throws. */
final class FlakyCache extends Repository
{
    public bool $down = false;

    public function __construct()
    {
        parent::__construct(new ArrayStore);
    }

    /** @param  \UnitEnum|array<array-key, mixed>|string  $key */
    public function get($key, $default = null): mixed
    {
        return $this->down ? throw new RuntimeException('redis is gone') : parent::get($key, $default);
    }

    public function forever($key, $value): bool
    {
        return $this->down ? throw new RuntimeException('redis is gone') : parent::forever($key, $value);
    }

    /** @param  \UnitEnum|array<array-key, mixed>|string  $key */
    public function add($key, $value, $ttl = null): bool
    {
        return $this->down ? throw new RuntimeException('redis is gone') : parent::add($key, $value, $ttl);
    }

    public function getStore()
    {
        return $this->down ? throw new RuntimeException('redis is gone') : parent::getStore();
    }
}
