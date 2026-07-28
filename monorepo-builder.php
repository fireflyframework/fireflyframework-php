<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;

return static function (MBConfig $mbConfig): void {
    // All runtime + tooling packages (incl. firefly/installer) are discovered here for
    // `bump-interdependency` / `release`. The actual git subtree split to the read-only Packagist
    // mirrors is driven by .github/workflows/release.yml (monorepo-builder v11 has no split command);
    // that workflow's split matrix is the authoritative mirror registry (26 units: every dir here
    // plus `skeleton/`, which is intentionally NOT registered below).
    //
    // `skeleton/` is deliberately excluded from packageDirectories: it keeps *@dev +
    // minimum-stability: dev until the family is live on Packagist (see docs/publishing.md), so it
    // must not be touched by `bump-interdependency` in this phase.
    $mbConfig->packageDirectories([__DIR__.'/packages']);
};
