<?php

declare(strict_types=1);

namespace Firefly\Kernel\Error;

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\FireflyException;

/**
 * RFC-7807-inspired error payload. Framework-agnostic: the web layer renders it
 * as problem+json. Timestamp is caller-supplied so the DTO stays pure/testable.
 */
final readonly class ErrorResponse
{
    /**
     * @param  list<FieldError>  $errors
     */
    public function __construct(
        public int $status,
        public string $title,
        public string $code,
        public ErrorCategory $category,
        public ErrorSeverity $severity,
        public ?string $detail = null,
        public ?string $type = null,
        public ?string $instance = null,
        public ?string $traceId = null,
        public array $errors = [],
        public ?string $timestamp = null,
    ) {}

    public static function fromException(
        FireflyException $e,
        ?string $instance = null,
        ?string $traceId = null,
        ?string $timestamp = null,
    ): self {
        $errors = $e instanceof ValidationException ? $e->fieldErrors() : [];

        return new self(
            status: $e->httpStatus(),
            title: self::titleFor($e->httpStatus()),
            code: $e->errorCode(),
            category: $e->category(),
            severity: $e->severity(),
            detail: $e->getMessage(),
            instance: $instance,
            traceId: $traceId,
            errors: $errors,
            timestamp: $timestamp,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'status' => $this->status,
            'title' => $this->title,
            'code' => $this->code,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
        ];

        foreach (['detail' => $this->detail, 'type' => $this->type, 'instance' => $this->instance, 'traceId' => $this->traceId, 'timestamp' => $this->timestamp] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        if ($this->errors !== []) {
            $data['errors'] = array_map(static fn (FieldError $fe): array => $fe->toArray(), $this->errors);
        }

        return $data;
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            412 => 'Precondition Failed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Error',
        };
    }
}
