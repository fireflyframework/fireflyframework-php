<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Transactional;

/**
 * A plain class with NO class-level #[Transactional] and NO method carrying #[Transactional]. The scanner's
 * proxy-exclusion guard must skip it entirely: it must not appear in the manifest's proxy map and
 * `hasProxyFor()` must report false for it.
 */
class PlainService
{
    public function ping(): string
    {
        return 'pong';
    }

    public function echoBack(string $value): string
    {
        return $value;
    }
}
