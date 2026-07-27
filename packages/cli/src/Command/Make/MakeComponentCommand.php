<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;

/**
 * `make:firefly-component` — scaffolds a #[Component] bean (pyfly's `generate component` parity).
 */
final class MakeComponentCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-component';

    /** @var string */
    protected $description = 'Create a Firefly #[Component].';

    /** @var string */
    protected $type = 'Firefly component';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/component.stub';
    }
}
