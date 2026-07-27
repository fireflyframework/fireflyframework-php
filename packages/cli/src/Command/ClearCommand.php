<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use FilesystemIterator;
use Firefly\Cli\Cache\FireflyCachePaths;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** The inverse of firefly:cache — recursively deletes ONLY FireflyCachePaths::dir(), nothing else. */
final class ClearCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:clear';

    /** @var string */
    protected $description = 'Delete the compiled manifests and #[Transactional] proxies from bootstrap/cache/firefly.';

    public function handle(): int
    {
        $dir = FireflyCachePaths::dir($this->laravel);
        if (! is_dir($dir)) {
            $this->info('firefly:clear — nothing to remove.');

            return self::SUCCESS;
        }

        /** @var iterable<\SplFileInfo> $it */
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);

        $this->info('firefly:clear — removed '.$dir);

        return self::SUCCESS;
    }
}
