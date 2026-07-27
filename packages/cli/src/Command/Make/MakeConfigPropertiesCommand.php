<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;

/**
 * `make:firefly-config-properties` — scaffolds a #[ConfigProperties] bound configuration DTO (pyfly's
 * `generate config-properties` parity).
 */
final class MakeConfigPropertiesCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-config-properties';

    /** @var string */
    protected $description = 'Create a Firefly #[ConfigProperties] configuration DTO.';

    /** @var string */
    protected $type = 'Firefly config properties';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/config-properties.stub';
    }
}
