<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\AutoConfiguration;

/**
 * Makes the capstone fixtures real component definitions — the Ledger singleton, the #[Service] saga/TCC/
 * workflow beans, the #[Component] hook — compiled with the REAL compiler, exactly as firefly:cache does
 * (the BankingComponentsProvider precedent).
 */
final class OrchestrationFixturesProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return self::compiled()[0];
    }

    protected function contextManifestPath(): string
    {
        return self::compiled()[1];
    }

    /** @return array{0: string, 1: string} */
    private static function compiled(): array
    {
        /** @var array{0: string, 1: string}|null $paths */
        static $paths = null;

        if ($paths === null) {
            $components = sys_get_temp_dir().'/firefly-orchestration-fixtures-components.php';
            $context = sys_get_temp_dir().'/firefly-orchestration-fixtures-context.php';
            (new AutoConfigManifestCompiler)->write(OrchestrationCapstoneTestCase::fixtures(), $components, $context);
            $paths = [$components, $context];
        }

        return $paths;
    }
}
