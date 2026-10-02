<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Registry;

use Firefly\FeatureFlags\Definition\FlagDocument;

/**
 * What every process knows about one source, kept in the application cache: its last good document (as JSON, so
 * `{}` survives any cache serializer), the revision it came from, when it was last checked, when it last refreshed
 * (a check that loaded a document or found nothing new: UTC ISO-8601 with seconds and `Z`), and the last error.
 * status(): UP (loaded, last check fine), STALE (loaded, last check failed), DOWN (never loaded).
 */
final readonly class SourceState
{
    public function __construct(
        public string $name,
        public bool $loaded,
        public ?string $document,
        public ?string $revision,
        public float $checkedAt,
        public ?string $lastRefresh,
        public ?string $error,
        public int $flags,
    ) {}

    public static function initial(string $name): self
    {
        return new self($name, false, null, null, 0.0, null, null, 0);
    }

    /** A state read back from the cache; null for anything toArray() did not write (a loaded state has a document). */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['name'] ?? null)) {
            return null;
        }

        $loaded = ($data['loaded'] ?? false) === true;
        $document = is_string($data['document'] ?? null) ? $data['document'] : null;
        if ($loaded && $document === null) {
            return null;
        }

        return new self(
            $data['name'],
            $loaded,
            $document,
            is_string($data['revision'] ?? null) ? $data['revision'] : null,
            is_float($data['checkedAt'] ?? null) || is_int($data['checkedAt'] ?? null) ? (float) $data['checkedAt'] : 0.0,
            is_string($data['lastRefresh'] ?? null) ? $data['lastRefresh'] : null,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            is_int($data['flags'] ?? null) ? $data['flags'] : 0,
        );
    }

    /**
     * @return array{name: string, loaded: bool, document: ?string, revision: ?string, checkedAt: float, lastRefresh: ?string, error: ?string, flags: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'loaded' => $this->loaded,
            'document' => $this->document,
            'revision' => $this->revision,
            'checkedAt' => $this->checkedAt,
            'lastRefresh' => $this->lastRefresh,
            'error' => $this->error,
            'flags' => $this->flags,
        ];
    }

    /** @throws \JsonException as FlagDocument::toJson() (never for a validated document) */
    public function withLoaded(FlagDocument $document, ?string $revision, float $now): self
    {
        return new self($this->name, true, $document->toJson(), $revision, $now, self::timestamp($now), null, count($document->flags));
    }

    /** A successful check that found nothing new: it refreshed, and any earlier error is over. */
    public function withUnchanged(float $now): self
    {
        return new self($this->name, $this->loaded, $this->document, $this->revision, $now, self::timestamp($now), null, $this->flags);
    }

    public function withFailure(string $error, float $now): self
    {
        return new self($this->name, $this->loaded, $this->document, $this->revision, $now, $this->lastRefresh, $error, $this->flags);
    }

    /** The same source, document, revision and error: only the check and refresh times may differ. */
    public function sameAs(self $other): bool
    {
        return $this->name === $other->name
            && $this->loaded === $other->loaded
            && $this->document === $other->document
            && $this->revision === $other->revision
            && $this->error === $other->error;
    }

    /** The last good document; an empty one when the source never loaded. */
    public function document(): FlagDocument
    {
        return $this->document === null ? new FlagDocument : FlagDocument::fromJson($this->document);
    }

    public function status(): string
    {
        return match (true) {
            ! $this->loaded => 'DOWN',
            $this->error !== null => 'STALE',
            default => 'UP',
        };
    }

    private static function timestamp(float $now): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) floor($now));
    }
}
