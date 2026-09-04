<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

/**
 * A PHPDoc comment, parsed into the things a generated document can actually use: the prose BODY, that same
 * body cut into a SUMMARY and a DESCRIPTION, and the `@tag` lines.
 *
 * WHY THIS EXISTS AT ALL. Before it, an operation's summary was `ucfirst()` of the humanised method name and
 * its description was the literal string "Handled by App\Web\OrderController::show()." — a placeholder
 * masquerading as documentation, and one that made every generated client's docblock and every viewer's
 * operation panel worse than blank, because a reader had to notice it said nothing before they could ignore
 * it. Meanwhile the prose that SHOULD have been there was sitting three lines above the method, already
 * written, in the docblock. Nothing needed authoring; something needed reading.
 *
 * WHY NOT phpdocumentor/reflection-docblock. It is the obvious dependency and it was rejected: it drags
 * webmozart/assert, a full type-expression parser and phpstan/phpdoc-parser behind it, all so this package
 * can read a summary, a paragraph, `@param` and `@deprecated`. A framework package that costs an application
 * four transitive dependencies to describe its own API is a bad trade, and the surface actually used here is
 * small enough to state exactly — which is what the rest of this class does.
 *
 * WHERE THE SUMMARY ENDS. phpDocumentor's own rule is "at the first blank line, or at a full stop followed by
 * a newline", which means a wrapped two-sentence paragraph becomes one enormous summary. That reads badly in
 * the one place a summary is used — a one-line entry in an operation list — so the rule here is the stricter
 * one people expect: the summary is the FIRST SENTENCE, and the rest of its paragraph flows into the
 * description. The known cost is an abbreviation: "Cancels an order, e.g. a draft one." would split at
 * "e.g." Internal-dot abbreviations (`e.g.`, `i.e.`, `cf.`) are therefore skipped over explicitly; a
 * trailing-dot one ("etc.", "vs.") is not, because at the end of a clause it is nearly always a real
 * sentence end and guessing wrong there costs more than it saves.
 *
 * TAG VALUES ARE RAW. Nothing here interprets a type expression, resolves a class alias, or validates a
 * `@param` against the signature. The only tag consumers are params() (member descriptions) and a presence
 * check on `@deprecated`; anything more ambitious belongs in the attribute family, where the author has
 * said what they mean rather than had it inferred from a comment.
 */
final readonly class DocBlock
{
    /**
     * $body is the WHOLE prose block, paragraph structure intact; $summary and $description are that same
     * text cut in two at the first sentence. Both spellings are kept because the document needs both and
     * neither reconstructs the other: an Operation Object has two slots and wants the cut, while a tag
     * description, a schema description and a property description each have ONE slot and want the text as
     * written. Rejoining summary and description with a paragraph break to serve the second case looked
     * equivalent and was not — it turned "Holds stock. Exactly one line per request." into two paragraphs,
     * which is a change to a sentence nobody wrote in two parts.
     *
     * @param  array<string, list<string>>  $tags  tag name (without `@`) => each occurrence's raw text
     */
    private function __construct(
        public string $body,
        public string $summary,
        public string $description,
        public array $tags,
    ) {}

    public static function empty(): self
    {
        return new self('', '', '', []);
    }

    /**
     * `false` is accepted because that is what every Reflection*::getDocComment() returns when there is no
     * comment, and forcing sixteen call sites to normalise it first would be sixteen chances to forget.
     */
    public static function parse(string|false|null $comment): self
    {
        if (! is_string($comment) || trim($comment) === '') {
            return self::empty();
        }

        [$raw, $tags] = self::split(self::lines($comment));

        $paragraphs = self::paragraphs($raw);
        [$summary, $description] = self::sentence($paragraphs);

        return new self(implode("\n\n", $paragraphs), $summary, $description, $tags);
    }

    /**
     * True when the comment carried nothing this generator can use. Distinct from "there was no comment":
     * a docblock holding only `@return array<string, mixed>` is present but says nothing about the operation,
     * and must fall through to the derived summary exactly as an absent one does.
     */
    public function isEmpty(): bool
    {
        return $this->body === '';
    }

    public function has(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    /**
     * Summary and description as ONE block, which is what every field that renders as a paragraph wants — a
     * tag description, a schema description. The sentence split exists so that an OPERATION can show a
     * one-line summary in a list and the rest in a panel; nothing else in the document has two such slots, and
     * dropping the remaining paragraphs there would silently discard most of what the author wrote.
     */
    public function prose(): string
    {
        return $this->body;
    }

    /**
     * Every occurrence of one tag, raw.
     *
     * @return list<string>
     */
    public function tag(string $name): array
    {
        return $this->tags[$name] ?? [];
    }

    /**
     * `@param` lines as member name => TYPE EXPRESSION — the mirror of params(), which returns the same
     * lines' prose.
     *
     * The two halves are split at the `$identifier`, because that is the one token whose position is fixed:
     * everything before it is the type (which contains spaces of its own, `array<string, mixed>`), and
     * everything after is English. A line with no type at all — `@param $id the id` — yields nothing rather
     * than an empty expression, so a caller never has to distinguish "untyped" from "typed as nothing".
     *
     * @return array<string, string>
     */
    public function paramTypes(): array
    {
        $types = [];

        foreach ($this->tags['param'] ?? [] as $line) {
            if (preg_match('/^\s*(.*?)\s*(?:\.\.\.)?\$([A-Za-z_]\w*)/s', $line, $matches) !== 1) {
                continue;
            }

            $type = trim($matches[1]);
            if ($type !== '') {
                $types[$matches[2]] = $type;
            }
        }

        return $types;
    }

    /**
     * The first `@return` line, raw — type expression and any prose after it, for DocType::split() to cut.
     *
     * Returned whole rather than pre-split because splitting it requires PARSING the type expression, and
     * this class deliberately interprets no types (see the class docblock). It hands the line to the one
     * place that does.
     */
    public function returnLine(): ?string
    {
        $lines = $this->tags['return'] ?? [];

        return $lines === [] ? null : trim($lines[0]);
    }

    /**
     * The first `@var` line's type expression, with any `$name` and trailing prose removed.
     *
     * `@var` is written three ways in the wild — bare (`@var list<Line>`), named (`@var list<Line> $lines`),
     * and described (`@var list<Line> the lines`) — and only the first token group is the type in all three.
     */
    public function varType(): ?string
    {
        $lines = $this->tags['var'] ?? [];

        if ($lines === []) {
            return null;
        }

        $line = trim($lines[0]);
        if (preg_match('/^(.*?)\s+\$[A-Za-z_]\w*/s', $line, $matches) === 1) {
            return trim($matches[1]);
        }

        return $line === '' ? null : $line;
    }

    /**
     * `@param` lines as member name => description, dropping the type expression and any line with no prose.
     *
     * The name is found by scanning for the first `$identifier` rather than by splitting on whitespace,
     * because a type expression contains spaces of its own (`array<string, mixed>`, `int|null`) and a
     * positional split would read the second half of the type as the variable name. A variadic `...$items`
     * falls out of the same scan.
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        $params = [];

        foreach ($this->tags['param'] ?? [] as $line) {
            if (preg_match('/\$([A-Za-z_]\w*)\s*(.*)$/s', $line, $matches) !== 1) {
                continue;
            }

            $description = self::flatten($matches[2]);
            if ($description !== '') {
                $params[$matches[1]] = $description;
            }
        }

        return $params;
    }

    /**
     * Strips the comment syntax: the `/**` opener, the `*\/` closer, and the leading `*` (plus at most ONE
     * following space) that decorates each line. Exactly one space is consumed so that an indented block
     * inside the comment — a code sample, a bullet list — keeps its relative indentation instead of being
     * flattened against the left margin.
     *
     * The opener takes its trailing spaces with it, which matters for the ONE-LINE form — a whole docblock
     * written between one `/**` and one closer, holding nothing but a return tag. Without that, the tag no
     * longer starts at column zero, split() (which anchors a tag at the start of its line) does not recognise
     * it, and the entire tag is read as prose and published as the operation's summary. It was, for exactly
     * as long as it took to look at the generated file.
     *
     * @return list<string>
     */
    private static function lines(string $comment): array
    {
        $inner = preg_replace('#^\s*/\*\*?[ \t]*|\s*\*/\s*$#s', '', $comment) ?? $comment;
        $lines = preg_split('/\R/', $inner);

        return array_map(
            static fn (string $line): string => (string) preg_replace('/^\s*\*[ ]?/', '', $line),
            $lines === false ? [$inner] : $lines,
        );
    }

    /**
     * Separates the prose body from the `@tag` block.
     *
     * A tag runs until the next tag or the end of the comment, so a wrapped `@param` description survives
     * whole. Continuation lines are kept as-is and joined with a newline; flatten() collapses them later,
     * at the one place a single-line value is actually needed.
     *
     * @param  list<string>  $lines
     * @return array{string, array<string, list<string>>}
     */
    private static function split(array $lines): array
    {
        $body = [];
        $tags = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^@([A-Za-z][\w-]*)\s*(.*)$/', $line, $matches) === 1) {
                $current = $matches[1];
                $tags[$current][] = $matches[2];

                continue;
            }

            if ($current === null) {
                $body[] = $line;

                continue;
            }

            $last = count($tags[$current]) - 1;
            $tags[$current][$last] = rtrim($tags[$current][$last]."\n".$line);
        }

        return [trim(implode("\n", $body)), $tags];
    }

    /**
     * The prose body as a list of paragraphs, each collapsed onto one line.
     *
     * Source comments are hard-wrapped at whatever column the author's editor used, and those breaks are an
     * artefact of the file rather than of the prose — carrying them into a JSON string would put ragged
     * newlines inside every description a viewer renders. Blank lines ARE meaningful and survive as the
     * boundaries between the entries returned here.
     *
     * @return list<string>
     */
    private static function paragraphs(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $split = preg_split('/\R[ \t]*\R/', $body);

        return array_values(array_filter(
            array_map(self::flatten(...), $split === false ? [$body] : $split),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));
    }

    /**
     * Splits the prose body into first sentence and remainder — see the class docblock for why the boundary
     * is a sentence rather than phpDocumentor's blank line.
     *
     * A paragraph break always wins over a sentence break when it comes first, so a deliberately terse
     * summary line followed by a blank line is never re-joined with the paragraph under it.
     *
     * @param  list<string>  $paragraphs
     * @return array{string, string}
     */
    private static function sentence(array $paragraphs): array
    {
        if ($paragraphs === []) {
            return ['', ''];
        }

        $first = $paragraphs[0];
        $rest = array_slice($paragraphs, 1);

        $end = self::sentenceEnd($first);
        if ($end !== null) {
            $tail = trim(substr($first, $end));
            $first = trim(substr($first, 0, $end));

            if ($tail !== '') {
                array_unshift($rest, $tail);
            }
        }

        return [$first, implode("\n\n", $rest)];
    }

    /**
     * The offset just past the first sentence-terminating `.`/`!`/`?`, or null when the paragraph is a single
     * sentence (or none at all).
     *
     * A terminator only counts when whitespace or the end of the paragraph follows it, which is what keeps
     * `3.14`, `v1.2` and `Firefly\OpenApi\Schema` from ending the sentence. Candidates whose preceding word
     * is an internal-dot abbreviation are skipped and scanning continues — see the class docblock.
     */
    private static function sentenceEnd(string $paragraph): ?int
    {
        if (preg_match_all('/[.!?](?=\s|$)/', $paragraph, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        foreach ($matches[0] as [, $offset]) {
            $end = $offset + 1;

            if ($end >= strlen($paragraph)) {
                return null;
            }

            if (preg_match('/(?:^|[\s(\[])(?:\p{L}\.)+\p{L}$/u', substr($paragraph, 0, $offset)) === 1) {
                continue;
            }

            return $end;
        }

        return null;
    }

    /**
     * Collapses one wrapped paragraph onto a single line — see paragraphs() for why the file's line breaks
     * must not survive into the document.
     */
    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
