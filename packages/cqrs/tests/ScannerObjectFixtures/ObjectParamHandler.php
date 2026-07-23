<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerObjectFixtures;

use Firefly\Cqrs\Attributes\CommandHandler;

/**
 * Sole fixture in its directory: a handler whose only handle() parameter is the BUILTIN `object` type and that
 * carries NO explicit #[CommandHandler(Message::class)] override. Scanned alone (no no-handle sibling to short the
 * scan first), the ONLY path to a throw is the ReflectionNamedType/isBuiltin() guard — so this isolates and pins
 * the builtin-param fail-loud branch under mutation testing (removing that guard makes the scan return quietly).
 */
#[CommandHandler]
final class ObjectParamHandler
{
    public function handle(object $command): void {}
}
