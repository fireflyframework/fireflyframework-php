<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Cli\Introspection\ActuatorCliRenderer;
use Firefly\Kernel\Version;
use Illuminate\Console\Command;

final class AboutCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:about';

    /** @var string */
    protected $description = 'Actuator-style boot introspection at the CLI: version, env, beans, conditions, mappings, scheduled tasks.';

    public function handle(ActuatorCliRenderer $renderer): int
    {
        $this->line('LaraFly '.Version::VERSION);
        foreach (['info', 'env', 'beans', 'conditions', 'mappings', 'scheduledtasks'] as $id) {
            $this->newLine();
            $this->info('# '.$id);
            $renderer->render($this, $id);
        }

        return self::SUCCESS;
    }
}
