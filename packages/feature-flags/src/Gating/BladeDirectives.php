<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Illuminate\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;

/** Conditional Blade directives backed by the same gate used by attributes and routes. */
final class BladeDirectives
{
    public static function register(BladeCompiler $blade): void
    {
        $blade->if('featureflag', static fn (string $key, bool $default = false): bool => self::gate()->allows($key, null, $default));
        $blade->if('featurevariant', static fn (string $key, string $variant): bool => self::gate()->allows($key, $variant));
    }

    private static function gate(): FeatureFlagGate
    {
        /** @var FeatureFlagGate */
        return Container::getInstance()->make(FeatureFlagGate::class);
    }
}
