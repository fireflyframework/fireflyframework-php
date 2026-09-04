<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

/**
 * One stack frame, with the two things that make a trace readable: whether it is YOURS, and what the code
 * around it says.
 *
 * A raw PHP trace is forty frames of which perhaps four are the application's, and the rest are the
 * framework walking its own dispatch. Marking the application's frames is what turns scrolling into
 * reading, and it is decided by path — a frame under `vendor/` belongs to a dependency — which is crude,
 * correct, and needs nothing installed.
 *
 * `$excerpt` is only ever populated for an application frame. Reading source for forty vendor frames would
 * open forty files to render code nobody is going to look at, and the page has to stay cheap: it renders
 * when things are already going wrong.
 */
final readonly class ErrorFrame
{
    /**
     * @param  array<int, string>  $excerpt  line number => source text, empty for a vendor frame
     */
    public function __construct(
        public string $file,
        public string $shortFile,
        public ?int $line,
        public string $call,
        public bool $vendor,
        public array $excerpt = [],
    ) {}
}
