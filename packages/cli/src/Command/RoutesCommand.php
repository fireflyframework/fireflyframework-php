<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Cli\Introspection\ActuatorCliRenderer;
use Illuminate\Console\Command;

final class RoutesCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:routes';

    /** @var string */
    protected $description = 'Render the compiled route table at the CLI (in-process, no HTTP).';

    public function handle(ActuatorCliRenderer $renderer): int
    {
        return $renderer->render($this, 'mappings');
    }
}
