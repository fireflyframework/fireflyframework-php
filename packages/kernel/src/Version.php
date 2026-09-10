<?php

declare(strict_types=1);

namespace Firefly\Kernel;

/**
 * The LaraFly framework version. CalVer YY.MM.Patch (composer.json carries no
 * version field — Packagist derives it from the git tag).
 *
 * The package test (VersionTest) enforces only the CalVer format. The root
 * test (tests/VersionConsistencyTest.php) enforces consistency with the
 * CHANGELOG's latest heading and the README version badge. The git tag
 * itself is stamped at release time and is not self-asserted here.
 */
final class Version
{
    public const string VERSION = '26.09.2';
}
