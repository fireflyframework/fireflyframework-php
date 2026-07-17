<?php

declare(strict_types=1);

namespace Firefly\Web\Http;

/**
 * Ordered, first-match converter registry. findWriter() parses the Accept header's q-values (with a
 * header-order tiebreak, matching pyfly's parse_accept) and returns the first converter that can write the
 * highest-ranked media type; if none matches, the first converter (JSON-native) is the default. A user can
 * replace this whole registry via a #[Bean] returning MessageConverterRegistry (#[ConditionalOnMissingBean]).
 */
final class MessageConverterRegistry
{
    /**
     * @param  list<MessageConverter>  $converters
     */
    public function __construct(private readonly array $converters) {}

    public function findWriter(string $accept): ?MessageConverter
    {
        foreach ($this->parseAccept($accept) as $mediaType) {
            foreach ($this->converters as $converter) {
                if ($converter->canWrite($mediaType)) {
                    return $converter;
                }
            }
        }

        return $this->converters[0] ?? null;
    }

    public function findReader(string $contentType): ?MessageConverter
    {
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));
        foreach ($this->converters as $converter) {
            if ($converter->canRead($mediaType)) {
                return $converter;
            }
        }

        return null;
    }

    /**
     * @return list<string> media types in descending q-value, ties broken by header order
     */
    private function parseAccept(string $accept): array
    {
        if (trim($accept) === '') {
            return [];
        }

        $entries = [];
        $index = 0;
        foreach (explode(',', $accept) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $segments = explode(';', $part);
            $q = 1.0;
            foreach (array_slice($segments, 1) as $segment) {
                $segment = trim($segment);
                if (str_starts_with($segment, 'q=')) {
                    $q = (float) substr($segment, 2);
                }
            }
            $entries[] = ['mediaType' => strtolower(trim($segments[0])), 'q' => $q, 'index' => $index++];
        }

        usort($entries, static function (array $a, array $b): int {
            return $b['q'] <=> $a['q'] ?: $a['index'] <=> $b['index'];
        });

        return array_map(static fn (array $entry): string => $entry['mediaType'], $entries);
    }
}
