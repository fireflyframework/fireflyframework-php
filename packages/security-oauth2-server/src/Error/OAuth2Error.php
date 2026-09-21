<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Error;

/** An RFC 6749 error (Spring's OAuth2Error): the code, a human sentence, and an optional URI. */
final readonly class OAuth2Error
{
    public function __construct(
        public string $errorCode,
        public string $description = '',
        public ?string $uri = null,
    ) {}

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        $document = ['error' => $this->errorCode];
        if ($this->description !== '') {
            $document['error_description'] = $this->description;
        }
        if ($this->uri !== null) {
            $document['error_uri'] = $this->uri;
        }

        return $document;
    }
}
