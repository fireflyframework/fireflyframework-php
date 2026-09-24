<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Cache\ManifestCacheWriter;
use Firefly\Context\Definition\StaleDefinitionReport;
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
        $report = (new ManifestCacheWriter)->write($psr4, $dir);

        $this->info(sprintf(
            'firefly:cache — wrote %d manifest(s) + %d proxy(ies) to %s',
            count($report->files),
            $report->proxyCount,
            $dir,
        ));

        $this->reportStaleEntries();

        return self::SUCCESS;
    }

    /**
     * Names the entries the boot that led here dropped, because it booted from a manifest that had gone
     * stale and they named classes autoloading could not find.
     *
     * Without this line the run is indistinguishable from a clean one — same "wrote N manifest(s)", same
     * exit 0 — and a developer would have no way to tell that the application they just booted was wired
     * differently from the file it was wired from. The classes named here are the ones the manifest just
     * written no longer mentions, so the message doubles as confirmation that the deletion took effect.
     *
     * A warning rather than info: nothing is wrong any more, but a dropped entry is still a fact worth
     * seeing in a wall of green. Guarded by bound() because the command has to keep working in a process
     * where FireflyAutoConfigureServiceProvider never registered — Lumen, a bare container harness — and
     * an unreported skip is a far smaller failure than a command that cannot run.
     */
    private function reportStaleEntries(): void
    {
        if (! $this->laravel->bound(StaleDefinitionReport::class)) {
            return;
        }

        /** @var StaleDefinitionReport $stale */
        $stale = $this->laravel->make(StaleDefinitionReport::class);
        if ($stale->isEmpty()) {
            return;
        }

        $classes = $stale->classes();

        $this->warn(sprintf(
            'firefly:cache — skipped %d stale manifest %s naming a class that no longer exists: %s',
            count($classes),
            count($classes) === 1 ? 'entry' : 'entries',
            implode(', ', $classes),
        ));
    }
}
