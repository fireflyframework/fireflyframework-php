<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Management;

use RuntimeException;
use Throwable;

/** A refused management operation: the §4.8 `{"error": code, "message": text}` body and its status. */
final class FlagManagementException extends RuntimeException
{
    public function __construct(public readonly ManagementError $error, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array{error: string, message: string}
     */
    public function toArray(): array
    {
        return ['error' => $this->error->value, 'message' => $this->getMessage()];
    }
}
