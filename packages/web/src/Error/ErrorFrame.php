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
     * @param  int  $index  the frame's 0-based position in the UNTRIMMED stack
     */
    public function __construct(
        public string $file,
        public string $shortFile,
        public ?int $line,
        public string $call,
        public bool $vendor,
        public array $excerpt = [],
        public int $index = 0,
    ) {}

    /** The directory part of $shortFile, WITH its trailing separator; '' when the path has none. */
    public function dir(): string
    {
        $at = $this->separator();

        return $at < 0 ? '' : substr($this->shortFile, 0, $at + 1);
    }

    /**
     * The file name, which is NEVER shortened.
     *
     * The page ellipsises the directory when a row runs out of width and never this: a row reading
     * `app/Http/Controllers/…` has told a reader nothing, and the file name is the half they came for.
     */
    public function base(): string
    {
        $at = $this->separator();

        return $at < 0 ? $this->shortFile : substr($this->shortFile, $at + 1);
    }

    /**
     * The Composer package this frame belongs to (`laravel/framework`), or null for application code.
     *
     * Read off $shortFile rather than $file, so it answers for exactly the path the page prints — and read
     * from the LAST `vendor/`, because a dependency that vendors its own dependencies is still identified by
     * the innermost one. The segment must be a whole directory name: `my-vendor/x/y` is a directory whose
     * name merely ends in the word, and labelling an application frame `x/y` because of it would be a lie
     * the reader has no way to check.
     */
    public function package(): ?string
    {
        foreach (['vendor/', 'vendor\\'] as $marker) {
            $at = strrpos($this->shortFile, $marker);

            if ($at === false || ($at !== 0 && $this->shortFile[$at - 1] !== '/' && $this->shortFile[$at - 1] !== '\\')) {
                continue;
            }

            $parts = preg_split('#[/\\\\]#', substr($this->shortFile, $at + strlen($marker))) ?: [];

            if (count($parts) >= 3 && $parts[0] !== '' && $parts[1] !== '') {
                return $parts[0].'/'.$parts[1];
            }
        }

        return null;
    }

    /** The index of the last directory separator in $shortFile, or -1 when there is none. */
    private function separator(): int
    {
        $slash = strrpos($this->shortFile, '/');
        $back = strrpos($this->shortFile, '\\');

        return max($slash === false ? -1 : $slash, $back === false ? -1 : $back);
    }
}
