<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Assembly;

use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Scanner\ManifestCompiler;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Scanner\ContextManifestCompiler;
use Firefly\Context\Scanner\ContextScanner;

/**
 * The ONLY compile entry point in firefly/autoconfigure — and it owns no reflection of its own: it delegates
 * to M2's ComponentScanner and M4's ContextScanner, which reflect exactly once, at compile time. It is never
 * called from a BootPass on the cached path (where manifests are loaded, not scanned), so an audit for
 * compile-time reflection usage over packages/autoconfigure/src turns up nothing here — every such call lives
 * in M2/M4 themselves. This is the library API M5's tests and M15's `firefly:cache` drive; M5 ships no CLI.
 */
final class AutoConfigManifestCompiler
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return array{0: ComponentManifest, 1: ContextManifest}
     */
    public function scan(array $psr4): array
    {
        $components = new ComponentManifest((new ComponentScanner)->scan($psr4));
        $context = new ContextManifest((new ContextScanner)->scan($psr4));

        return [$components, $context];
    }

    /**
     * @param  array<string,string>  $psr4
     */
    public function write(array $psr4, string $componentManifestPath, string $contextManifestPath): void
    {
        (new ManifestCompiler)->write((new ComponentScanner)->scan($psr4), $componentManifestPath);
        (new ContextManifestCompiler)->write((new ContextScanner)->scan($psr4), $contextManifestPath);
    }
}
