<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Support;

/**
 * The uncached boot with `firefly.validation.messages` set to `laravel`: the shipped ValidationServiceProvider
 * reads the key into ValidationSettings and the default adapter keeps Laravel's sentences.
 */
abstract class LaravelMessagesBootTestCase extends UncachedBootTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.validation.messages' => 'laravel'];
    }
}
