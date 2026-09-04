<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * The typed result of a delete or an update: the outcome, a sentence explaining it, and what was touched.
 *
 * `$reason` is always populated, including on success ("Deleted."), so the view has one field to render in
 * every branch instead of a match over the enum. Every reason produced by DataBrowser is a fixed sentence
 * composed in this layer — never an exception message, for the reason DataListing's docblock sets out at
 * length: a QueryException's message carries the SQL and its bindings.
 *
 * `$changed` lists the columns an update actually wrote, which is not the same as the columns it was given: a
 * submitted form round-trips every field, and the ones whose value did not change, plus the identifier and
 * any masked secret, are dropped. The view uses it to say what happened; a test uses it to prove the
 * identifier and the secrets were dropped.
 */
final readonly class DataWriteResult
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public DataWriteOutcome $outcome,
        public string $reason,
        public ?string $resource = null,
        public int|string|null $id = null,
        public array $changed = [],
    ) {}

    /**
     * @param  list<string>  $changed
     */
    public static function done(string $reason, ?string $resource = null, int|string|null $id = null, array $changed = []): self
    {
        return new self(DataWriteOutcome::Done, $reason, $resource, $id, $changed);
    }

    public static function refused(string $reason, ?string $resource = null, int|string|null $id = null): self
    {
        return new self(DataWriteOutcome::Refused, $reason, $resource, $id);
    }

    public static function notFound(string $reason, ?string $resource = null, int|string|null $id = null): self
    {
        return new self(DataWriteOutcome::NotFound, $reason, $resource, $id);
    }

    public static function failed(string $reason, ?string $resource = null, int|string|null $id = null): self
    {
        return new self(DataWriteOutcome::Failed, $reason, $resource, $id);
    }

    public function isDone(): bool
    {
        return $this->outcome === DataWriteOutcome::Done;
    }

    public function isRefused(): bool
    {
        return $this->outcome === DataWriteOutcome::Refused;
    }

    public function isNotFound(): bool
    {
        return $this->outcome === DataWriteOutcome::NotFound;
    }

    public function isFailed(): bool
    {
        return $this->outcome === DataWriteOutcome::Failed;
    }
}
