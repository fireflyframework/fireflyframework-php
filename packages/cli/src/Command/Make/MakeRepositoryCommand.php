<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;

/**
 * `make:firefly-repository` — scaffolds a repository interface extending the framework's
 * `Firefly\Data\Repository\CrudRepository` port (pyfly's `generate repository` parity).
 */
final class MakeRepositoryCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-repository';

    /** @var string */
    protected $description = 'Create a Firefly repository interface (extends CrudRepository).';

    /** @var string */
    protected $type = 'Firefly repository';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/repository.stub';
    }
}
