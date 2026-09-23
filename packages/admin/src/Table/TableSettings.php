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
 * first option instead, and submitting the form then resizes the table the operator was reading.
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
            if (ctype_digit($part) && (int) $part >= 1 && (int) $part <= $max) {
                $sizes[] = (int) $part;
            }
        }

        $sizes[] = $default;
        $sizes = array_values(array_unique($sizes));
        sort($sizes);

        return $sizes;
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
