<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Contracts\Support\Htmlable;

/**
 * A fragment of markup.
 */
final readonly class Banner implements Htmlable
{
    public function __construct(
        public string $text,
    ) {}

    public function toHtml(): string
    {
        return '<p>'.e($this->text).'</p>';
    }
}
