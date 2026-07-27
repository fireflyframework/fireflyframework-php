<?php

declare(strict_types=1);

namespace App;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * Binds the `greeting.*` configuration subtree onto this readonly DTO and registers it as a container
 * singleton, so it can be constructor-injected wherever GreetingProperties is requested.
 */
#[ConfigProperties('greeting')]
final readonly class GreetingProperties
{
    public function __construct(public string $salutation = 'Hello') {}
}
