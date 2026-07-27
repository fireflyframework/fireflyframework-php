<?php

declare(strict_types=1);

namespace Firefly\Installer;

/**
 * Fakeable seam around external process execution so NewCommand can be unit-tested
 * without shelling out to composer/git.
 */
interface ProcessRunner
{
    /**
     * @param  list<string>  $command  argv, first element is the program
     * @return int the process exit code (0 = success)
     */
    public function run(array $command, ?string $cwd = null): int;
}
