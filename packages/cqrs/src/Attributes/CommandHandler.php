<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Attributes;

use Attribute;
use Firefly\Container\Attributes\Component;

/**
 * Marks a class as a command handler. Specialises #[Component] (like #[Service]/#[Repository]), so ONE annotation
 * does two jobs: the shipped ComponentScanner registers the handler as a constructor-injected DI bean (via
 * IS_INSTANCEOF stereotype matching — no separate #[Service] needed), and the HandlerScanner reads it to build the
 * command -> handler registry manifest. The optional $command is the explicit message class-string that overrides
 * scan-time inference from the handle() parameter (design §2.1). Inert metadata — carries no dispatch logic.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CommandHandler extends Component
{
    public function __construct(public readonly ?string $command = null)
    {
        parent::__construct();
    }
}
