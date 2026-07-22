<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\AutoConfiguration;

/**
 * Makes the Ordering fixtures real component definitions (so RegisterBeanPostProcessorsPass installs an extender
 * for PlaceOrderService and the container can resolve OrderRepository). Compiles the component/context manifests
 * inline with the REAL compiler — exactly what firefly:cache does for an app in production (M15).
 */
final class OrderingComponentsProvider extends AutoConfiguration
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
            $components = sys_get_temp_dir().'/firefly-data-ordering-components.php';
            $context = sys_get_temp_dir().'/firefly-data-ordering-context.php';
            $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Ordering\\' => dirname(__DIR__).'/Fixtures/Ordering'];

            (new AutoConfigManifestCompiler)->write($psr4, $components, $context);

            $paths = [$components, $context];
        }

        return $paths;
    }
}
