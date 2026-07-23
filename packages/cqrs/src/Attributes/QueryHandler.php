<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Attributes;

use Attribute;
use Firefly\Container\Attributes\Component;

/**
 * Marks a class as a query handler. The read-side twin of #[CommandHandler]: specialises #[Component] (so the
 * ComponentScanner registers it as a bean) and is read by HandlerScanner to build the query -> handler manifest.
 * The optional $query is the explicit message class-string overriding inference from the handle() parameter. Inert.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class QueryHandler extends Component
{
    public function __construct(public readonly ?string $query = null)
    {
        parent::__construct();
    }
}
