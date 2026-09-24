<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

use Firefly\Config\Config;

/**
 * How every listing on the dashboard pages, sizes and spaces itself.
 *
 * THE OFFERED SET IS CLOSED, NOT MERELY CAPPED, and that is the difference between a control and a
 * suggestion. `?size=` arrives in a URL an operator can hand-edit, and the honest answers to "give me
 * 19 999 rows" are a cap or a refusal — a cap silently renders a page nobody asked for, so this refuses and
 * falls back to the configured default. The set is also what the rows-per-page `<select>` renders, so the
 * configured default is ALWAYS a member of it: a `<select>` whose current value has no `<option>` shows the
 * first option instead, and submitting the form then resizes the table the operator was reading. A size that
 * reaches a listing from BELOW this object — the data browser's own cap, which caps where this refuses — is
 * spliced into the rendered set by `offering()`, so that same `<select>` can still say where it is.
 *
 * `max-height` IS INTERPOLATED INTO THE STYLESHEET, as the `--table-vh` custom property the scroll container
 * reads, which makes it the one key on this object that is not merely wrong when it is wrong. A value is
 * matched against a length pattern and refused outright rather than escaped, because there is no escaping
 * that makes `70vh;}body{display:none` safe inside a `<style>` element — the parser has already left the
 * declaration by the time any entity would be decoded. `none` is admitted on purpose: it is how a deployment
 * turns the scrollport, and with it the sticky header, off.
 *
 * `remember-scroll` is the other half of that move. Giving `.tw` a height took the scrollport away from
 * `main`, and a browser restores the DOCUMENT's scroll offset across a reload but not an inner scroller's —
 * so the ten-second auto-refresh, which had cost a reader nothing, started dropping them back to row 1 of
 * the page they were reading. The dashboard therefore saves each wrapper's offset per URL and puts it back,
 * and this key is how a deployment declines. It defaults to ON because it restores parity rather than
 * inventing an affordance: the restore is applied only on a reload or a back/forward, which are precisely
 * the navigations where the browser would have restored the offset itself before this wave moved it.
 *
 * The ceiling is `DataBrowserSettings::PAGE_SIZE_CEILING`'s, repeated rather than imported: the browser's
 * cap exists because one request can materialise a table into PHP memory, and this one exists because one
 * page can render a hundred thousand `<tr>`s into a response. Same number, different failure, and a shared
 * constant would tie the two to each other for no reason beyond their agreeing today.
 */
final readonly class TableSettings
{
    /** The largest `max-page-size` an application may configure — past this, one page is a denial of service. */
    public const int PAGE_SIZE_CEILING = 1000;

    public const string DENSITY_COMFORTABLE = 'comfortable';

    public const string DENSITY_COMPACT = 'compact';

    /** A CSS length with an explicit unit, or `none`. Anything else does not reach the stylesheet. */
    private const string HEIGHT_PATTERN = '/^(none|[0-9]+(\.[0-9]+)?(px|vh|svh|dvh|rem|em))$/';

    /**
     * @param  list<int>  $pageSizes  the sizes the rows-per-page control offers, ascending
     */
    public function __construct(
        public int $pageSize = 50,
        public array $pageSizes = [25, 50, 100, 200],
        public int $maxPageSize = 200,
        public string $maxHeight = '68vh',
        public string $density = self::DENSITY_COMFORTABLE,
        public bool $rememberScroll = true,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $max = min(self::PAGE_SIZE_CEILING, max(1, $config->int('firefly.admin.table.max-page-size', 200)));
        $size = min($max, max(1, $config->int('firefly.admin.table.page-size', 50)));

        return new self(
            pageSize: $size,
            pageSizes: self::sizes($config->string('firefly.admin.table.page-sizes', '25,50,100,200'), $max, $size),
            maxPageSize: $max,
            maxHeight: self::height($config->string('firefly.admin.table.max-height', '68vh')),
            density: self::density($config->string('firefly.admin.table.density', self::DENSITY_COMFORTABLE)),
            rememberScroll: $config->bool('firefly.admin.table.remember-scroll', true),
        );
    }

    /** A caller-supplied size survives only by being one of the offered ones; everything else is the default. */
    public function clamp(?int $requested): int
    {
        return $requested !== null && in_array($requested, $this->pageSizes, true)
            ? min($this->maxPageSize, $requested)
            : $this->pageSize;
    }

    /**
     * The same settings under a SECOND, tighter pair of bounds — the shape a listing that has page-size keys
     * of its own needs.
     *
     * THE DATA BROWSER HAS TWO OF EVERY BOUND AND ONE CONTROL. `firefly.admin.data.page-size` and
     * `firefly.admin.data.max-page-size` are its own keys and neither is decoration: the cap exists because a
     * size that is merely large on an actuator payload materialises a whole table into PHP memory on a
     * repository that cannot page, and the default is 25 rather than 50 because a row of a customer table is
     * wider than a row of a bean listing. Applying them AFTER the query has been parsed is what turns them
     * into lies — the page then states `?size=` explicitly on every call, so the browser's own default can
     * never be reached, and a size its cap lowered leaves the rows-per-page `<select>` with no `<option>` to
     * show. Composing them HERE, before the request is read, makes every mechanism downstream agree by
     * construction: `clamp()` falls back to the browser's default and refuses a size it may not serve,
     * `ListingQuery::meaningful()` omits `size` when it IS that default so a link stays at it, and the
     * offered set is the shared one narrowed to what this listing may actually serve — with the configured
     * default forced into it, exactly as `fromConfig()` does, so a deployment that asks for ten rows is
     * OFFERED ten rows rather than being unable to say where it is.
     *
     * The shared keys that are not bounds — the density, the scrollport height, whether a scroll offset is
     * remembered — are carried through untouched: they are facts about the dashboard's chrome, and a listing
     * does not get its own.
     */
    public function boundedBy(int $pageSize, int $maxPageSize): self
    {
        $max = min($this->maxPageSize, max(1, $maxPageSize));
        $size = min($max, max(1, $pageSize));

        return new self(
            pageSize: $size,
            pageSizes: self::offered($this->pageSizes, $max, $size),
            maxPageSize: $max,
            maxHeight: $this->maxHeight,
            density: $this->density,
            rememberScroll: $this->rememberScroll,
        );
    }

    /**
     * The offered set, with the size a listing was actually SERVED spliced in when it is not a member.
     *
     * A CONTROL MUST BE ABLE TO SAY WHERE IT IS, and the closed set alone does not guarantee that.
     * `clamp()` only ever returns a member and `boundedBy()` folds the data browser's own bounds in before a
     * request is parsed, so on every listing in the tree today this returns the set unchanged. It exists
     * because `ListingQuery::sized()` does not have to: a query RE-STATED at the size it was served carries
     * whatever the source served, and a `<select>` whose current value has no `<option>` shows the first one
     * instead — pressing Apply then resizes the table the operator was reading, which is the exact failure
     * the closed set exists to prevent. So the served size is spliced in, ascending, the same way
     * `fromConfig()` forces the configured default into the set.
     *
     * @return list<int>
     */
    public function offering(int $size): array
    {
        if (in_array($size, $this->pageSizes, true)) {
            return $this->pageSizes;
        }

        $sizes = [...$this->pageSizes, $size];
        sort($sizes);

        return $sizes;
    }

    /**
     * The horizontal cell padding, published as `--row-x` so that a `<col>` width can subtract it.
     *
     * This is the number that makes a rigid column honest. `box-sizing:border-box` is global on this page
     * (layout.blade.php:111), so a `<col style="width:7.5ch">` is 7.5 characters INCLUDING both paddings —
     * about 34px of content on a 14px-padded cell, in which the verb `DELETE` clips. Every rigid width is
     * therefore emitted as `calc(<n>ch + 2 * var(--row-x))`, and this is where the value comes from.
     */
    public function rowPaddingX(): string
    {
        return $this->density === self::DENSITY_COMPACT ? '10px' : '14px';
    }

    public function rowPaddingY(): string
    {
        return $this->density === self::DENSITY_COMPACT ? '5px' : '8px';
    }

    /**
     * @return list<int>
     */
    private static function sizes(string $configured, int $max, int $default): array
    {
        $sizes = [];
        foreach (explode(',', $configured) as $part) {
            $part = trim($part);
            if (ctype_digit($part)) {
                $sizes[] = (int) $part;
            }
        }

        return self::offered($sizes, $max, $default);
    }

    /**
     * The sizes a listing may actually serve, ascending and distinct, with the default always among them.
     *
     * One function rather than two so that a set narrowed by `boundedBy()` is built by the same rule as the
     * one `fromConfig()` reads: the ceiling drops what is past it, and the default is forced in afterwards
     * rather than filtered — it is already inside the ceiling by construction, and a `<select>` whose current
     * value has no `<option>` shows the first one and resizes the table on the next submit.
     *
     * @param  list<int>  $sizes
     * @return list<int>
     */
    private static function offered(array $sizes, int $max, int $default): array
    {
        $offered = array_values(array_filter($sizes, static fn (int $size): bool => $size >= 1 && $size <= $max));
        $offered[] = $default;
        $offered = array_values(array_unique($offered));
        sort($offered);

        return $offered;
    }

    private static function height(string $configured): string
    {
        $height = strtolower(trim($configured));

        return preg_match(self::HEIGHT_PATTERN, $height) === 1 ? $height : '68vh';
    }

    private static function density(string $configured): string
    {
        return strtolower(trim($configured)) === self::DENSITY_COMPACT
            ? self::DENSITY_COMPACT
            : self::DENSITY_COMFORTABLE;
    }
}
