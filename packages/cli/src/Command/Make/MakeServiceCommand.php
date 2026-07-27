<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;

/**
 * `make:firefly-service` — scaffolds a #[Service] bean (pyfly's `generate service` parity).
 */
final class MakeServiceCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-service';

    /** @var string */
    protected $description = 'Create a Firefly #[Service].';

    /** @var string */
    protected $type = 'Firefly service';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/service.stub';
    }
}
