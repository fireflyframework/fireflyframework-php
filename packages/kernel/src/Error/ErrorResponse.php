<?php

declare(strict_types=1);

namespace Firefly\Kernel\Error;

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\FireflyException;

/**
 * RFC 9457 error payload. Framework-agnostic: the web layer renders it as problem+json. Timestamp is
 * caller-supplied so the DTO stays pure/testable.
 *
 * `extensions` are RFC 9457 extension members — the document's open namespace beside the members this class
 * defines. They are spread FIRST in toArray() and the standard members written over them, so an extension
 * named `status`, `code` or `title` can never replace the real one: the document's identity is the
 * exception's, and an application building a problem from user-supplied context must not be able to lie
 * about it by accident.
 */
final readonly class ErrorResponse
{
    /** The members this class defines; an extension of the same name never reaches the document. */
    public const array STANDARD_MEMBERS = ['status', 'title', 'code', 'category', 'severity', 'detail', 'type', 'instance', 'traceId', 'timestamp', 'errors'];

    /**
     * @param  list<FieldError>  $errors
     * @param  array<string, mixed>  $extensions  RFC 9457 extension members
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
        public array $extensions = [],
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
            title: $e->title() ?? self::titleFor($e->httpStatus()),
            code: $e->errorCode(),
            category: $e->category(),
            severity: $e->severity(),
            detail: $e->getMessage(),
            instance: $instance,
            traceId: $traceId,
            errors: $errors,
            timestamp: $timestamp,
            extensions: $e->extensions(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        // Extensions first, standard members over them: see the class comment for why the order is the rule.
        $data = array_diff_key($this->extensions, array_flip(self::STANDARD_MEMBERS));

        $data += [
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

        // The standard members lead the document whatever order the extensions arrived in, so two problems
        // with different extensions still read the same way to a person and diff the same way to a tool.
        $standard = array_intersect_key($data, array_flip(self::STANDARD_MEMBERS));

        return $standard + $data;
    }

    /**
     * The status's reason phrase (RFC 9110 §15), used as `title` when the exception has none of its own.
     * Public because the web layer needs the same phrase for the throwables it maps itself (a router's 405)
     * and a second table of the same strings would drift from this one.
     */
    public static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            412 => 'Precondition Failed',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Error',
        };
    }
}
