<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Attributes;

use Attribute;

/**
 * Marks a #[Query] method as a STATEMENT (update/delete/insert) rather than a query: it runs through
 * Connection::affectingStatement() and returns the affected-row count. Spring Data's @Modifying, with the
 * same two rules — it needs a #[Query] (a derived `deleteBy…` is already a statement) and its SQL must not be
 * a SELECT; both are refused at scan time. `requiresTransaction` (Spring's @Transactional on the same method,
 * folded in): the statement refuses to run outside an active transaction unless set to false.
 * `clearAutomatically` is accepted for source compatibility and is a documented no-op — Eloquent has no
 * persistence context to clear. INERT METADATA: TransactionalScanner records it, EloquentRepository reads it.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Modifying
{
    public function __construct(
        public bool $requiresTransaction = true,
        public bool $clearAutomatically = false,
    ) {}
}
