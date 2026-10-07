<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Registry;

use Firefly\FeatureFlags\Definition\FlagDocument;

/** Where FireflyFlagProvider reads the document it evaluates (FlagRegistry in production). */
interface FlagDocumentSource
{
    public function document(): FlagDocument;
}
