<?php

declare(strict_types=1);

namespace Firefly\Admin;

use Firefly\Config\Config;

final readonly class BeanGraphSettings
{
    public function __construct(
        public int $depth = 2,
        public int $maxRows = 16,
        public int $maxNodes = 72,
        public int $maxPaths = 3,
        public int $pageSize = 50,
        public int $starters = 12,
        public int $moduleMaxNodes = 40,
        public int $beansPageSize = 50,
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            depth: min(4, max(1, $config->int('firefly.admin.graph.focus.depth', 2))),
            maxRows: min(60, max(4, $config->int('firefly.admin.graph.focus.max-rows', 16))),
            maxNodes: min(300, max(8, $config->int('firefly.admin.graph.focus.max-nodes', 72))),
            maxPaths: min(10, max(0, $config->int('firefly.admin.graph.focus.max-paths', 3))),
            pageSize: min(500, max(10, $config->int('firefly.admin.graph.focus.page-size', 50))),
            starters: min(50, max(1, $config->int('firefly.admin.graph.starters', 12))),
            moduleMaxNodes: min(200, max(0, $config->int('firefly.admin.graph.modules.max-nodes', 40))),
            beansPageSize: min(500, max(10, $config->int('firefly.admin.beans.page-size', 50))),
        );
    }
}
