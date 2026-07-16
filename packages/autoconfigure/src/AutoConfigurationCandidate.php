<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure;

/**
 * One discovered auto-configuration package's candidacy: the provider FQCN that recorded it (the collector's
 * dedupe key) plus the two compiled manifest paths describing that package's own #[Configuration] classes.
 * The paths are loaded — never scanned — at phase 200 (AutoConfigDiscoveryPass), keeping the boot path
 * reflection-free.
 */
final readonly class AutoConfigurationCandidate
{
    public function __construct(
        public string $provider,
        public string $componentManifestPath,
        public string $contextManifestPath,
    ) {}
}
