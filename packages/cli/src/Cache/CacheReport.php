<?php

declare(strict_types=1);

namespace Firefly\Cli\Cache;

/** What firefly:cache emitted — the emitted file paths + the proxy-class count. */
final readonly class CacheReport
{
    /** @param list<string> $files */
    public function __construct(public array $files, public int $proxyCount = 0) {}
}
