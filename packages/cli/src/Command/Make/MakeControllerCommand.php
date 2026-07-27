<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;

/**
 * `make:firefly-controller` — scaffolds a #[RestController] with a sample #[GetMapping] action into
 * app/Http (pyfly's `generate controller` parity).
 */
final class MakeControllerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-controller';

    /** @var string */
    protected $description = 'Create a Firefly #[RestController].';

    /** @var string */
    protected $type = 'Firefly controller';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/controller.stub';
    }

    /**
     * MUST keep $rootNamespace UNTYPED: the parent GeneratorCommand::getDefaultNamespace($rootNamespace)
     * declares no parameter type, and PHP's parameter-contravariance rule requires an override's
     * parameter type to be at least as broad as the parent's — "no type" is the broadest possible, so a
     * native `string $rootNamespace` here would be a PHP FATAL at class-load time, not a lint nit.
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Http';
    }
}
