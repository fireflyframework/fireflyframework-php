<?php

declare(strict_types=1);

namespace Firefly\Data;

use Firefly\Config\Config;

/**
 * The `firefly.data.*` keys, read once into a value object — the DataBrowserSettings/ManagementServerSettings
 * idiom, so every consumer (translator, template, listener pass, the admin datasource page) sees one answer.
 *
 * Every default is the safe one for an application that never wrote the key: translation ON (a
 * QueryException that reaches a controller is a 500 with the statement in it; a DuplicateKeyException is a
 * 409 the client can act on — and nothing that catches QueryException stops working, because the original is
 * `previous`), NO default transaction timeout (0 — a timeout nobody asked for is a rollback nobody expected),
 * driver statement timeouts ON whenever a timeout IS set (the wall-clock check alone cannot interrupt a
 * statement that is already running), transactional listeners ON (nothing registers unless a method
 * carries the attribute), and paged projections ON (a method that asks for a Page by declaring one gets a
 * page; the old behaviour handed back every matching row and broke the declared return type).
 */
final readonly class DataSettings
{
    public function __construct(
        public bool $exceptionTranslation = true,
        public int $defaultTimeout = 0,
        public bool $statementTimeout = true,
        public bool $transactionalEventListeners = true,
        public bool $pagedProjections = true,
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            exceptionTranslation: $config->bool('firefly.data.exception-translation.enabled', true),
            defaultTimeout: max(0, $config->int('firefly.data.transaction.default-timeout', 0)),
            statementTimeout: $config->bool('firefly.data.transaction.statement-timeout', true),
            transactionalEventListeners: $config->bool('firefly.data.transactional-event-listeners.enabled', true),
            pagedProjections: $config->bool('firefly.data.projection.pageable', true),
        );
    }
}
