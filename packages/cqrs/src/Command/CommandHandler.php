<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Command;

/**
 * OPTIONAL marker interface for a command handler — a documentary type-marker only. PHP method parameter types are
 * invariant across an interface, so this contract deliberately declares NO handle() method (a handle(object) could
 * not be narrowed to handle(CreateOrder) by a concrete handler). By convention an annotated handler exposes exactly
 * one public method named handle taking one typed message parameter and returning the result; the HandlerScanner
 * records that method name and the bus invokes it dynamically. Handlers are discovered by the #[CommandHandler]
 * attribute alone — implementing this marker is not required by the registry (which stores callables).
 */
interface CommandHandler {}
