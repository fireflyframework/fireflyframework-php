<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One token of an authorization (Spring's OAuth2Authorization.Token): its type, the SHA-256 of its value, when
 * it was issued and when it expires, and metadata — `invalidated` (revoked or consumed), `claims` (the JWT's
 * claims, what introspection answers), `scopes`, `format`. The plain `value` is present ONLY on a token this
 * process just issued: the services strip it before storing (withoutValue), so no store ever holds a value.
 *
 * Immutable: every change is a with-er returning a copy. `expiresAt` is compared with `<=` so a token is expired
 * AT its expiry instant, never one tick after — the same rule the JWT `exp` claim follows. toArray()/fromArray()
 * carry everything but the value, in ATOM form, which is what the Eloquent driver writes into a metadata column.
 */
final readonly class OAuth2Token
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public OAuth2TokenType $type,
        public string $hash,
        public DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $expiresAt,
        public array $metadata = [],
        public ?string $value = null,
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public static function issue(OAuth2TokenType $type, string $value, DateTimeImmutable $issuedAt, ?DateTimeImmutable $expiresAt, array $metadata = []): self
    {
        return new self($type, TokenHash::of($value), $issuedAt, $expiresAt, $metadata, $value);
    }

    public function withoutValue(): self
    {
        return new self($this->type, $this->hash, $this->issuedAt, $this->expiresAt, $this->metadata);
    }

    public function withMetadata(string $key, mixed $value): self
    {
        return new self($this->type, $this->hash, $this->issuedAt, $this->expiresAt, [$key => $value] + $this->metadata, $this->value);
    }

    public function invalidated(): self
    {
        return $this->withMetadata('invalidated', true);
    }

    public function isInvalidated(): bool
    {
        return ($this->metadata['invalidated'] ?? false) === true;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return ! $this->isInvalidated() && ! $this->isExpired($now);
    }

    /**
     * @return array{type: string, hash: string, issued_at: string, expires_at: string|null, metadata: array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'hash' => $this->hash,
            'issued_at' => $this->issuedAt->format(DateTimeInterface::ATOM),
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array{type: string, hash: string, issued_at: string, expires_at: string|null, metadata: array<string,mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            OAuth2TokenType::from($data['type']),
            $data['hash'],
            new DateTimeImmutable($data['issued_at']),
            $data['expires_at'] === null ? null : new DateTimeImmutable($data['expires_at']),
            $data['metadata'],
        );
    }
}
