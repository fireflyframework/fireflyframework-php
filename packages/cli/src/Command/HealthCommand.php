<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Cli\Introspection\ActuatorCliRenderer;
use Illuminate\Console\Command;

final class HealthCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:health';

    /** @var string */
    protected $description = 'Render the actuator health status at the CLI (in-process, no HTTP).';

    public function handle(ActuatorCliRenderer $renderer): int
    {
        return $renderer->render($this, 'health');
    }
}
