<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * A #[ConfigProperties] DTO shaped to exercise everything /configprops has to render in one row: a plain
 * scalar, a nested DTO built by ReflectionConfigBinder, a scalar whose NAME is sensitive, and — the case the
 * masking audit was about — an ARRAY whose name is sensitive, so the test can prove the whole subtree is
 * replaced rather than descended into.
 */
#[ConfigProperties(prefix: 'demo')]
final readonly class DemoProperties
{
    /**
     * @param  array<string, string>  $signingKeys
     */
    public function __construct(
        public string $name,
        public int $retries,
        public string $apiToken,
        public array $signingKeys,
        public DemoEndpointProperties $endpoint,
        public DemoMode $mode = DemoMode::Strict,
    ) {}
}
