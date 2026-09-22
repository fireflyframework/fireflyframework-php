<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use RuntimeException;
use Throwable;

/**
 * Base of the LaraFly exception taxonomy. Product-agnostic: every subclass
 * carries a stable string error code, an HTTP status, a category and a severity
 * so the web layer can render a consistent RFC 9457 response.
 *
 * TWO MORE THINGS A REFUSAL NEEDS, AND WHY THEY LIVE HERE. RFC 9457 lets a problem document carry
 * *extension members* — `{"field": "limit"}`, `{"allowed": ["POST"]}`, `{"requiredAuthorities": [...]}` —
 * beside the standard ones, and it lets `title` be the problem type's own short phrase rather than the
 * status's reason phrase. Neither had a home on this class, so an application that wanted either had to
 * define its own refusal type outside the taxonomy, catch it in every controller and build the document by
 * hand — one real application did exactly that at a hundred and forty-four call sites, with twenty-six
 * private `problem()` helpers and a renderable for the places that forgot. The fix belongs on the base
 * class: `ErrorResponse::fromException()` reads both accessors, so a subclass — or a `withExtensions()` call
 * at the throw site — is all it takes for the members to reach the wire.
 *
 * The accessors are fluent and return `$this` rather than a copy, deliberately. Every subclass fixes its
 * code/status/category through a constructor whose signature is its own (`ConflictException($message,
 * $errorCode, $previous)`), so a "with" that had to re-run the constructor would need to know every
 * subclass's argument list. Mutating the instance is what `Exception` already is — a mutable object whose
 * trace is filled in after construction — and it keeps `throw (new ConflictException(...))->withExtensions(
 * [...])` a one-liner with no subclass cooperation.
 *
 * Extension keys never override a standard member: `ErrorResponse::toArray()` writes the standard members
 * over the extensions, so a `['status' => 999]` extension is harmless rather than a way to lie about the
 * status. That rule is enforced where the document is built, not here, because the document is the only
 * place where the two namespaces meet.
 */
class FireflyException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $extensions;

    private ?string $title;

    /**
     * @param  array<string, mixed>  $extensions  RFC 9457 extension members spread into the problem document
     * @param  ?string  $title  the problem's own short phrase; null means the status's reason phrase
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 500,
        private readonly ErrorCategory $category = ErrorCategory::Internal,
        private readonly ErrorSeverity $severity = ErrorSeverity::Error,
        ?Throwable $previous = null,
        array $extensions = [],
        ?string $title = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->extensions = $extensions;
        $this->title = $title;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function category(): ErrorCategory
    {
        return $this->category;
    }

    public function severity(): ErrorSeverity
    {
        return $this->severity;
    }

    /**
     * RFC 9457 extension members. Empty unless the throw site or a subclass supplied some.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /**
     * Replaces the extension members on THIS instance and returns it, so a throw site reads
     * `throw (new ConflictException('…'))->withExtensions(['field' => 'name'])`.
     *
     * @param  array<string, mixed>  $extensions
     * @return $this
     */
    public function withExtensions(array $extensions): static
    {
        $this->extensions = $extensions;

        return $this;
    }

    /** The problem's own title, or null when the status's reason phrase should be used. */
    public function title(): ?string
    {
        return $this->title;
    }

    /**
     * @return $this
     */
    public function withTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }
}
