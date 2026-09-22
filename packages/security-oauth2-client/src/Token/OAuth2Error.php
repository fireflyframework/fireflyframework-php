<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

/** An OAuth2 error (RFC 6749 §5.2; Spring's OAuth2Error): the code, and the description and URI the provider may add. */
final readonly class OAuth2Error
{
    public function __construct(
        public string $errorCode,
        public string $description = '',
        public ?string $uri = null,
    ) {}

    public function describe(): string
    {
        return $this->description === '' ? $this->errorCode : "{$this->errorCode} ({$this->description})";
    }
}
