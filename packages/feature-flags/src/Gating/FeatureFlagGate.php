<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Firefly\Config\Config;
use Firefly\Container\Container as FireflyContainer;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Throwable;

/** Shared decision for method, route and view gates. */
final class FeatureFlagGate
{
    public const string MIDDLEWARE_ALIAS = 'feature-flag';

    private readonly int $disabledStatus;

    public function __construct(private readonly FireflyContainer $beans, Config $config)
    {
        $this->disabledStatus = FeatureFlagsSettings::disabledStatus($config);
    }

    public function allows(string $key, ?string $variant = null, bool $default = false): bool
    {
        try {
            $flags = $this->beans->has(FeatureFlags::class) ? $this->beans->get(FeatureFlags::class) : null;
            if (! $flags instanceof FeatureFlags) {
                return $default;
            }

            if ($variant === null) {
                return $flags->isEnabled($key, $default);
            }

            $resolved = $flags->variant($key);

            return $resolved === null ? $default : $resolved === $variant;
        } catch (Throwable) {
            return $default;
        }
    }

    public function disabled(string $key): FeatureFlagDisabledException
    {
        return new FeatureFlagDisabledException($key, $this->disabledStatus);
    }
}
