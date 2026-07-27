<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Illuminate\Console\Command;

/** Thin passthrough to Laravel's own migrate/db:seed/migrate:fresh commands — reimplements nothing. */
final class DbCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:db {action=migrate : migrate|seed|fresh}';

    /** @var string */
    protected $description = 'Thin passthrough to Laravel database commands (migrate/seed/fresh).';

    public function handle(): int
    {
        $action = $this->argument('action');
        $action = is_string($action) ? $action : 'migrate';

        return match ($action) {
            'seed' => $this->call('db:seed'),
            'fresh' => $this->call('migrate:fresh'),
            default => $this->call('migrate'),
        };
    }
}
