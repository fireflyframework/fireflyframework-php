<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\AutoConfiguration;

/**
 * Makes the Listeners fixtures real component definitions (NoteService is proxied, NoteAudit is a resolvable
 * #[Component]) with the REAL compiler — the FixtureComponentsProvider idiom over a different directory.
 */
final class ListenersComponentsProvider extends AutoConfiguration
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
            $components = sys_get_temp_dir().'/firefly-data-listeners-components.php';
            $context = sys_get_temp_dir().'/firefly-data-listeners-context.php';
            $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Listeners\\' => dirname(__DIR__).'/Fixtures/Listeners'];

            (new AutoConfigManifestCompiler)->write($psr4, $components, $context);

            $paths = [$components, $context];
        }

        return $paths;
    }
}
