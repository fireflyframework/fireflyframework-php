<?php

declare(strict_types=1);

namespace Firefly\Web\Http;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The only built-in converter: Laravel-native JSON. Handles application/json and any +json suffix type.
 * Arrayable is normalised to its array first; JsonSerializable is honoured natively by json_encode.
 */
final class JsonMessageConverter implements MessageConverter
{
    public function mediaTypes(): array
    {
        return ['application/json'];
    }

    public function canWrite(string $mediaType): bool
    {
        return $this->isJson($mediaType);
    }

    public function write(mixed $data, string $mediaType): string
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function canRead(string $mediaType): bool
    {
        return $this->isJson($mediaType);
    }

    public function read(string $body, string $type, string $mediaType): mixed
    {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function isJson(string $mediaType): bool
    {
        $mediaType = strtolower(trim(explode(';', $mediaType)[0]));

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }
}
