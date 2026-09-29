<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/**
 * The dashboard capstone, given a Conditions page whose BOTH panels actually page.
 *
 * The Conditions page is the only one carrying two listings at once, and the mechanism that makes that work
 * — each panel's links carrying the OTHER panel's position — lives entirely inside `_pager`'s paged branch,
 * because `ListingPage::link()` is called nowhere else. The bare table capstone cannot reach it: an
 * auto-configured testbench applies nine conditions and this harness backs two of them off, and eleven rows
 * spread over two panels fit on every size the rows-per-page control offers by default, so `isPaged()` is
 * false on both and the branch never renders. Paging the Applied panel would then rebuild a URL with no
 * `neg_page` in it and the Backed-off panel would jump back to page 1 while the reader was looking
 * somewhere else — the exact bug the carrying exists to prevent, with nothing failing.
 *
 * So this case changes TWO things and nothing else, the same two AdminTablePagedCapstoneTestCase changes
 * for Routes. It OFFERS a page size of 2 — `firefly.admin.table.page-sizes`, seeded through
 * configOverrides() rather than set inside a test, because AdminSettings (and the TableSettings on it) is
 * built once by AdminRouteRegistrar during the boot passes and bound as an instance, so a `config()->set()`
 * from a test body arrives after the object that would have read it. And it grows the backed-off side to
 * five rows, which at two a page is three pages: enough for a page in the middle, a first-page jump that
 * drops its own `page` while keeping its neighbour's, and a Next with nowhere to go.
 *
 * The filler is deliberately `App\Widgets\…`: `?neg_q=Redis` must keep narrowing to the one backed-off
 * cache row the parent seeds, so no filler class or condition may contain a term a search test looks for.
 */
abstract class AdminTableConditionsPagedCapstoneTestCase extends AdminTableCapstoneTestCase
{
    /** Three pages at two rows a page — see the class docblock for why three and not two. */
    public const int BACKED_OFF = 5;

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.table.page-sizes' => '2,25,50',
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    protected function backedOff(): array
    {
        $rows = parent::backedOff();

        // Zero-padded for the same reason the route filler is: the Backed-off panel is ordered by class,
        // and `…02` sorting before `…10` under one reading and after it under another would make a
        // page-boundary assertion a coin toss.
        for ($n = count($rows) + 1; $n <= self::BACKED_OFF; $n++) {
            $suffix = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $rows[] = [
                'App\Widgets\WidgetAutoConfiguration'.$suffix,
                'Firefly\Context\Condition\Attributes\ConditionalOnProperty',
                '(firefly.widgets.'.$suffix.'.enabled=false) did not match required value \'true\'',
            ];
        }

        return $rows;
    }
}
