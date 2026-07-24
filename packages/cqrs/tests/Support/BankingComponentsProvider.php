<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\AutoConfiguration;

/**
 * Makes the Banking fixtures real component definitions (so RegisterBeanPostProcessorsPass installs an extender for
 * OpenAccountHandler/FailOpenAccountHandler and the container can resolve AccountRepository/FindAccountHandler).
 * Compiles the component/context manifests inline with the REAL compiler — exactly what firefly:cache does for an
 * app in production (M15). Mirrors packages/data/tests/Support/OrderingComponentsProvider.php.
 */
final class BankingComponentsProvider extends AutoConfiguration
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
            $components = sys_get_temp_dir().'/firefly-cqrs-banking-components.php';
            $context = sys_get_temp_dir().'/firefly-cqrs-banking-context.php';
            $psr4 = ['Firefly\\Cqrs\\Tests\\CapstoneFixtures\\Banking\\' => dirname(__DIR__).'/CapstoneFixtures/Banking'];
            (new AutoConfigManifestCompiler)->write($psr4, $components, $context);
            $paths = [$components, $context];
        }

        return $paths;
    }
}
