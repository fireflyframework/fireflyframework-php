<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

/** Binds an uploaded file into a Firefly\Web\Http\UploadedFile value object. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class UploadedFile
{
    public function __construct(public readonly ?string $name = null) {}
}
