<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;

/**
 * `make:firefly-entity` — scaffolds a DDD entity (pyfly's `generate entity` parity). There is no
 * `#[Entity]` attribute anywhere in the monorepo (verified by grepping every packages/*\/src/Attributes
 * dir): the real framework analog is the pure-PHP `Firefly\Domain\Entity` abstract base class
 * (identity-based equality, zero reflection — packages/domain/src/Entity.php), so the generated class
 * extends it instead of applying a nonexistent attribute.
 */
final class MakeEntityCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-entity';

    /** @var string */
    protected $description = 'Create a Firefly domain entity (extends Firefly\\Domain\\Entity).';

    /** @var string */
    protected $type = 'Firefly entity';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/entity.stub';
    }
}
