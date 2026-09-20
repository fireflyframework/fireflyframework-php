<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\Attributes\EntityGraph;
use Firefly\Data\Repository\EloquentRepository;

/**
 * Names a graph it never declares — the "unknown named entity graph" case, refused at first use rather than at
 * scan time because $entityGraphs is runtime state the scanner does not read.
 *
 * @extends EloquentRepository<Record>
 */
#[Repository]
class GraphlessRecordRepository extends EloquentRepository
{
    protected string $model = Record::class;

    /** @return list<Record> */
    #[EntityGraph('Record.nope')]
    public function findAll(): array
    {
        return parent::findAll();
    }
}
