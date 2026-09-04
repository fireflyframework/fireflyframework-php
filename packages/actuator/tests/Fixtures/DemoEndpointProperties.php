<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Fixtures;

/**
 * The nested half of DemoProperties. Carries NO #[ConfigProperties] attribute of its own — that is exactly how
 * ReflectionConfigBinder builds a nested DTO (from the parent's sub-array, never from a second manifest row),
 * so /configprops has to reach it by reflecting the parent instance rather than by listing it separately.
 */
final readonly class DemoEndpointProperties
{
    public function __construct(
        public string $url,
        public string $password,
    ) {}
}
