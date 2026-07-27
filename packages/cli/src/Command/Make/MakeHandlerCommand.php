<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `make:firefly-handler` — scaffolds a #[CommandHandler] by default, or a #[QueryHandler] with
 * `--query` (pyfly's `generate handler` parity, split by CQRS side via one flag).
 */
final class MakeHandlerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-handler';

    /** @var string */
    protected $description = 'Create a Firefly #[CommandHandler] (or #[QueryHandler] with --query).';

    /** @var string */
    protected $type = 'Firefly handler';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/'.($this->option('query') ? 'query-handler.stub' : 'command-handler.stub');
    }

    /** @return list<array{0: string, 1: string|null, 2: int, 3: string}> */
    protected function getOptions(): array
    {
        return [
            ['query', null, InputOption::VALUE_NONE, 'Generate a #[QueryHandler] instead of a #[CommandHandler].'],
        ];
    }
}
