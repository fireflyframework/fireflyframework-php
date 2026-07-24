<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Correlation;

/**
 * A thin request-scoped holder of the active correlation id the buses propagate and the bridge stamps into
 * integration-event headers (x-correlation-id). begin() sets the active id (an explicit value, else the currently
 * active id, else a fresh uuid4) and returns the PRIOR id; end($prior) restores it — the pattern that prevents an
 * id leaking into the next command on the same worker (pyfly audit #98). uuid4 uses random_bytes + string formatting
 * (no ramsey/uuid, no reflection), the same approach as firefly/domain's DomainEvent. Octane: this is per-request
 * state and must be flushed between requests (known-latent). No distributed-tracing ExecutionContext in v1.
 */
final class CorrelationContext
{
    private ?string $current = null;

    public function currentId(): ?string
    {
        return $this->current;
    }

    public function begin(?string $id = null): ?string
    {
        $prior = $this->current;
        $this->current = $id ?? $this->current ?? self::uuid4();

        return $prior;
    }

    public function end(?string $prior): void
    {
        $this->current = $prior;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        /** @var array<int, string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks);
    }
}
