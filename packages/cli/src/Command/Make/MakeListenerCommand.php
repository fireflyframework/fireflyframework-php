<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `make:firefly-listener` — scaffolds an #[EventListener] method by default, or a #[MessageListener]
 * with `--message` (pyfly's `generate listener` parity, split by transport via one flag).
 */
final class MakeListenerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-listener';

    /** @var string */
    protected $description = 'Create a Firefly #[EventListener] (or #[MessageListener] with --message).';

    /** @var string */
    protected $type = 'Firefly listener';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/'.($this->option('message') ? 'message-listener.stub' : 'event-listener.stub');
    }

    /** @return list<array{0: string, 1: string|null, 2: int, 3: string}> */
    protected function getOptions(): array
    {
        return [
            ['message', null, InputOption::VALUE_NONE, 'Generate a #[MessageListener] instead of an #[EventListener].'],
        ];
    }
}
