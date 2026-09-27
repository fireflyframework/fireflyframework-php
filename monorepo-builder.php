<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;

return static function (MBConfig $mbConfig): void {
    // Component manifests document module dependencies; validate keeps their versions consistent.
    // The root library is authoritative for installation. Never merge or split these descriptors.
    $mbConfig->packageDirectories([__DIR__.'/packages']);
};
