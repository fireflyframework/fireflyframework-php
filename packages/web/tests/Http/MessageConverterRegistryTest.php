<?php

declare(strict_types=1);

use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Illuminate\Contracts\Support\Arrayable;

function fakeXmlConverter(): MessageConverter
{
    return new class implements MessageConverter
    {
        public function mediaTypes(): array
        {
            return ['application/xml'];
        }

        public function canWrite(string $mediaType): bool
        {
            return $mediaType === 'application/xml';
        }

        public function write(mixed $data, string $mediaType): string
        {
            return '<xml/>';
        }

        public function canRead(string $mediaType): bool
        {
            return $mediaType === 'application/xml';
        }

        public function read(string $body, string $type, string $mediaType): mixed
        {
            return [];
        }
    };
}

it('writes JSON honouring Arrayable and JsonSerializable', function () {
    $converter = new JsonMessageConverter;

    $arrayable = new class implements Arrayable
    {
        /** @return array<string,int> */
        public function toArray(): array
        {
            return ['a' => 1];
        }
    };

    expect($converter->write(['x' => 2], 'application/json'))->toBe('{"x":2}')
        ->and($converter->write($arrayable, 'application/json'))->toBe('{"a":1}')
        ->and($converter->canWrite('application/vnd.api+json'))->toBeTrue()
        ->and($converter->read('{"y":3}', 'array', 'application/json'))->toBe(['y' => 3]);
});

it('selects the writer by q-value, highest first', function () {
    $registry = new MessageConverterRegistry([new JsonMessageConverter, fakeXmlConverter()]);

    expect($registry->findWriter('application/xml;q=0.9, application/json;q=0.8')?->mediaTypes()[0])->toBe('application/xml')
        ->and($registry->findWriter('application/json;q=0.9, application/xml;q=0.2')?->mediaTypes()[0])->toBe('application/json');
});

it('breaks a q-value tie by Accept header order', function () {
    $registry = new MessageConverterRegistry([new JsonMessageConverter, fakeXmlConverter()]);

    expect($registry->findWriter('application/xml, application/json')?->mediaTypes()[0])->toBe('application/xml')
        ->and($registry->findWriter('application/json, application/xml')?->mediaTypes()[0])->toBe('application/json');
});

it('honours q-value over header order, not header order over q-value', function () {
    $registry = new MessageConverterRegistry([new JsonMessageConverter, fakeXmlConverter()]);

    // json appears first in the header but has the lower q; xml has the higher q and must win.
    // A naive first-token/header-order selector would incorrectly return json here.
    expect($registry->findWriter('application/json;q=0.2, application/xml;q=0.9')?->mediaTypes())
        ->toContain('application/xml');
});

it('falls back to the first converter when nothing matches Accept', function () {
    $registry = new MessageConverterRegistry([new JsonMessageConverter, fakeXmlConverter()]);

    expect($registry->findWriter('text/csv')?->mediaTypes()[0])->toBe('application/json');
});

it('finds a reader by content type, ignoring parameters', function () {
    $registry = new MessageConverterRegistry([new JsonMessageConverter]);

    expect($registry->findReader('application/json; charset=utf-8'))->toBeInstanceOf(JsonMessageConverter::class)
        ->and($registry->findReader('text/plain'))->toBeNull();
});
