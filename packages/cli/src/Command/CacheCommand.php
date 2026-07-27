<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Illuminate\Console\Command;

final class CacheCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:cache';

    /** @var string */
    protected $description = 'Compile all app manifests and emit #[Transactional] proxies into bootstrap/cache/firefly for a zero-reflection boot.';

    public function handle(): int
    {
        /** @var array<string,string> $psr4 */
        $psr4 = $this->laravel->make('config')->get('firefly.scan.paths', []);
        if ($psr4 === []) {
            $this->warn('firefly.scan.paths is empty — nothing to compile.');

            return self::SUCCESS;
        }

        $dir = FireflyCachePaths::dir($this->laravel);
        $report = (new ManifestCacheWriter)->writeManifests($psr4, $dir);

        $this->info(sprintf('firefly:cache — wrote %d manifest(s) to %s', count($report->files), $dir));

        return self::SUCCESS;
    }
}
