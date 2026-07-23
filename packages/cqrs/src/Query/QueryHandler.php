<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Query;

/**
 * OPTIONAL marker interface for a query handler — a documentary type-marker, twin of Command\CommandHandler. Declares
 * no handle() method (PHP parameter invariance); an annotated handler exposes one public handle(<Query>) method that
 * the HandlerScanner records and the bus invokes dynamically. Discovery is by the #[QueryHandler] attribute alone.
 */
interface QueryHandler {}
