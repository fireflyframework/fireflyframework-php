<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Registry\FlagDocumentSource;
use RuntimeException;

/** A fixed document for FireflyFlagProvider, and a switch that makes reading it fail. */
final class StaticDocument implements FlagDocumentSource
{
    public bool $broken = false;

    public function __construct(public FlagDocument $document) {}

    public static function json(string $json): self
    {
        return new self(FlagDocument::fromJson($json));
    }

    public function document(): FlagDocument
    {
        if ($this->broken) {
            throw new RuntimeException('the cache is on fire');
        }

        return $this->document;
    }
}
