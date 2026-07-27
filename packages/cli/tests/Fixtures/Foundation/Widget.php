<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Domain\Entity;

/**
 * A DDD domain entity — the firefly/domain analog of an @Entity (there is no #[Entity] attribute; the analog is
 * the abstract base class Firefly\Domain\Entity, per T6). Identity-based equality is inherited; `name` is the
 * carried value. Held in-memory by WidgetStore during the CQRS flow.
 */
final class Widget extends Entity
{
    public function __construct(public readonly string $name, int|string|null $id = null)
    {
        parent::__construct($id);
    }
}
