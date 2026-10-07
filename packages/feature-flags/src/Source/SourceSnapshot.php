<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Source;

use Firefly\FeatureFlags\Definition\FlagDocument;

/** A validated document and the revision it was read at (what the next load() compares against). */
final readonly class SourceSnapshot
{
    public function __construct(
        public FlagDocument $document,
        public ?string $revision,
    ) {}
}
