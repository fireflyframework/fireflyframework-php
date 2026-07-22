<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\AutoConfiguration;

/**
 * A test-only discovered provider that makes the capstone fixtures under tests/Fixtures/Capstone real component
 * definitions (so RegisterBeanPostProcessorsPass installs an extender for AccountService and the BPP proxies it).
 * It compiles the component/context manifests inline with the REAL compiler — exactly what firefly:cache does for
 * an app in production (M15).
 */
final class FixtureComponentsProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return self::compiled()[0];
    }

    protected function contextManifestPath(): string
    {
        return self::compiled()[1];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function compiled(): array
    {
        /** @var array{0: string, 1: string}|null $paths */
        static $paths = null;

        if ($paths === null) {
            $components = sys_get_temp_dir().'/firefly-data-capstone-components.php';
            $context = sys_get_temp_dir().'/firefly-data-capstone-context.php';
            $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Capstone\\' => dirname(__DIR__).'/Fixtures/Capstone'];

            (new AutoConfigManifestCompiler)->write($psr4, $components, $context);

            $paths = [$components, $context];
        }

        return $paths;
    }
}
