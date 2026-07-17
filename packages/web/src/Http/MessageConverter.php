<?php

declare(strict_types=1);

namespace Firefly\Web\Http;

/** A content-negotiation converter: writes a return value / reads a request body for a media type. */
interface MessageConverter
{
    /** @return list<string> media types this converter emits (first is the canonical Content-Type) */
    public function mediaTypes(): array;

    public function canWrite(string $mediaType): bool;

    public function write(mixed $data, string $mediaType): string;

    public function canRead(string $mediaType): bool;

    public function read(string $body, string $type, string $mediaType): mixed;
}
