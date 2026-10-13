<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Tests\Support;

/** The same application with firefly.callbacks.enabled=false. */
abstract class CallbacksDisabledCapstoneTestCase extends CallbacksCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.callbacks.enabled' => false] + parent::configOverrides();
    }
}
