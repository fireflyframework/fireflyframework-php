<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

/**
 * One stack frame, with the two things that make a trace readable: whether it is YOURS, and what the code
 * around it says.
 *
 * Both of its halves — the path and the call — are offered SPLIT, because the page prints each on one line
 * and a line runs out of width. `dir()`/`base()` and `callQualifier()`/`callFunction()` are the same idea
 * twice: a qualifier that may be ellipsised, and the token that identifies the frame and never may.
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
     * The part of $call BEFORE its last qualifier — the class or the namespace — without the separator;
     * '' when the call carries none (`throw`, `array_map()`, `{closure}`).
     *
     * This is the half of a call a row is allowed to clip, and it is the half a path clips too: for
     * `Illuminate\Database\Eloquent\Builder->get()` it is everything the neighbouring `Builder.php` already
     * says, printed once more only because a reader scanning for a namespace wants to see it.
     */
    public function callQualifier(): string
    {
        $at = $this->callSplit();

        return $at < 0 ? '' : substr($this->call, 0, $at);
    }

    /**
     * The function half of $call, WITH the separator that introduces it (`->get()`, `::make()`, `\collect()`).
     *
     * NEVER shortened, for the same reason base() is not. A Laravel trace is sixty `Illuminate\…` frames
     * whose qualifiers differ by a segment or two and whose METHOD NAMES are the only tokens that tell them
     * apart; a row that clipped its way to `Illuminate\Database\Eloq…` would have printed sixty identical
     * lines.
     */
    public function callFunction(): string
    {
        $at = $this->callSplit();

        return $at < 0 ? $this->call : substr($this->call, $at);
    }

    /**
     * The offset of the last `->`, `::` or `\` in $call, or -1 when there is none.
     *
     * Searched only BEFORE the first `{`, because a closure names itself inside braces and PHP 8.4 puts a
     * file path in there — `App\Jobs\Sync::{closure:C:\app\Jobs\Sync.php:31}()` carries three separators
     * that belong to a Windows path, and cutting at one of those would split the descriptor in half.
     */
    private function callSplit(): int
    {
        $brace = strpos($this->call, '{');
        $scan = $brace === false ? $this->call : substr($this->call, 0, $brace);

        $at = -1;
        foreach (['->', '::', '\\'] as $marker) {
            $found = strrpos($scan, $marker);

            if ($found !== false && $found > $at) {
                $at = $found;
            }
        }

        return $at;
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
