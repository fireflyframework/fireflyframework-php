<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\AutoConfiguration;

/** The capstone fixtures as real component definitions, compiled with the REAL compiler (the OrchestrationFixturesProvider precedent). */
final class EventSourcingFixturesProvider extends AutoConfiguration
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
            $components = sys_get_temp_dir().'/firefly-eventsourcing-fixtures-components.php';
            $context = sys_get_temp_dir().'/firefly-eventsourcing-fixtures-context.php';
            (new AutoConfigManifestCompiler)->write(['Firefly\\EventSourcing\\Tests\\CapstoneFixtures\\' => dirname(__DIR__).'/CapstoneFixtures'], $components, $context);
            $paths = [$components, $context];
        }

        return $paths;
    }
}
