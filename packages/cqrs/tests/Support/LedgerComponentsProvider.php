<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\AutoConfiguration;

/** The Ledger fixtures' component + context manifests, compiled once per process (the BankingComponentsProvider shape). */
final class LedgerComponentsProvider extends AutoConfiguration
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
            $components = sys_get_temp_dir().'/firefly-cqrs-ledger-components.php';
            $context = sys_get_temp_dir().'/firefly-cqrs-ledger-context.php';
            $psr4 = ['Firefly\\Cqrs\\Tests\\CapstoneFixtures\\Ledger\\' => dirname(__DIR__).'/CapstoneFixtures/Ledger'];
            (new AutoConfigManifestCompiler)->write($psr4, $components, $context);
            $paths = [$components, $context];
        }

        return $paths;
    }
}
