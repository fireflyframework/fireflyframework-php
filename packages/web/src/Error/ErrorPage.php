<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

/**
 * The HTML error page, built as a string.
 *
 * WHY NOT BLADE. This page renders when the application is already failing, and a Blade view is the one
 * thing that cannot be relied on then: the failure may BE a view — a compile error, a missing path, a view
 * factory that never got bound because the container is half-built — and rendering a second view to explain
 * the first produces a white screen and no clue. So the page has no dependencies at all: no container
 * lookups, no view factory, no filesystem read of its own, and no CSS or font from a network the box may not
 * have. String concatenation is not the elegant choice; it is the one that still works when nothing else
 * does.
 *
 * THE DESIGN IS THE FRAMEWORK'S, not a fourth one. The palette, the type stack and the radii are the admin
 * dashboard's — this is a diagnostic surface and it should read as one — while the composition is the
 * welcome page's: centred, generous, a single column. A person meets this page in the same session in which
 * they meet those two, and a third visual language would just be noise.
 *
 * THE SIGNATURE IS THE TRACE, because that is what the page is FOR. A raw PHP trace is a hundred frames of
 * which ten are yours; here the application's frames are the list — accented, one line each, the first one
 * with its source already open — and every dependency frame sits behind a single closed disclosure below
 * them. That split is the entire difference between scrolling a trace and reading one, and it is drawn with
 * `<details>` and CSS — no JavaScript, so it works with scripts disabled and in whatever a container's
 * minimal browser turns out to be.
 */
final class ErrorPage
{
    public static function render(ErrorReport $report, ErrorPageSettings $settings): string
    {
        $title = self::e($report->status.' '.$report->reason.' · '.$settings->title);

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$title.'</title>'
            .self::favicon()
            .'<style>'.self::css().'</style></head><body>'
            .'<main class="sheet">'
            .self::header($report, $settings)
            .self::facts($report)
            .self::detail($report)
            .self::footer($report, $settings)
            .'</main></body></html>';
    }

    private static function header(ErrorReport $report, ErrorPageSettings $settings): string
    {
        $tone = match (true) {
            $report->status >= 500 => 'down',
            $report->status >= 400 => 'warn',
            default => 'idle',
        };

        $html = '<header class="head">'
            .'<span class="mark"><span class="dot"></span>'.self::e($settings->title).'</span>'
            .'<p class="status '.$tone.'"><b>'.self::e((string) $report->status).'</b><span>'.self::e($report->reason).'</span></p>';

        // The stable error code is the one thing worth carrying off this page — into a support ticket, into
        // a log search — so it is the largest thing under the status rather than a detail in a table.
        $html .= '<p class="code">'.self::e($report->code).'</p>';

        if ($report->detailed && $report->message !== '') {
            $html .= '<p class="message">'.self::e($report->message).'</p>';
        } elseif (! $report->detailed) {
            $html .= '<p class="message muted">'.self::e(self::reassurance($report->status, $report->reference)).'</p>';
        }

        return $html.'</header>';
    }

    private static function facts(ErrorReport $report): string
    {
        $rows = [
            'Request' => $report->method.' '.$report->path,
            'Code' => $report->code,
            'Category' => $report->category,
            'Severity' => $report->severity,
            'When' => $report->timestamp,
            'Reference' => $report->reference,
        ];

        // The correlation id beside the trace id, and only when the two differ: with tracing off the
        // reference IS the correlation id, and a second row repeating it would teach a reader that the two
        // ids are interchangeable — which is the confusion keeping them apart exists to prevent. The
        // Reference row above is untouched, label and markup both; it is what a person is told to quote.
        if ($report->correlationId !== '' && $report->correlationId !== $report->reference) {
            $rows['Correlation'] = $report->correlationId;
        }

        if ($report->detailed) {
            $rows['Exception'] = $report->exceptionClass;
            $rows['Thrown at'] = $report->location;
        }

        $html = '<dl class="facts">';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $html .= '<div><dt>'.self::e($label).'</dt><dd>'.self::e($value).'</dd></div>';
        }

        return $html.'</dl>';
    }

    private static function detail(ErrorReport $report): string
    {
        if (! $report->detailed) {
            return '';
        }

        return self::previous($report).self::frames($report);
    }

    private static function previous(ErrorReport $report): string
    {
        if ($report->previous === []) {
            return '';
        }

        // The outermost message is usually the least specific one — firefly/web wraps a binding failure, the
        // container wraps a constructor throw — so the chain is shown in full and near the top rather than
        // buried under forty frames.
        $html = '<section class="panel"><h2>Caused by</h2><ol class="chain">';
        foreach ($report->previous as $link) {
            $html .= '<li><p class="cls">'.self::e($link['class']).'</p>'
                .'<p class="msg">'.self::e($link['message']).'</p>'
                .'<p class="loc">'.self::e($link['location']).'</p></li>';
        }

        return $html.'</ol></section>';
    }

    /**
     * The trace, split into the frames a reader came for and the ones they came through.
     *
     * A raw PHP trace is a hundred frames of which ten are the application's, and interleaving them is what
     * makes a trace something to scroll rather than something to read. So the application's frames are the
     * LIST — accented, in stack order, the first one with source already open — and the dependencies are a
     * single closed disclosure underneath. Nothing is hidden: the count is on both, and one click or one
     * Enter opens the whole set.
     *
     * Every frame keeps its position in the UNTRIMMED stack (`#37`), so a split list still reads as a stack
     * and a budgeted one still says where its gaps are.
     *
     * THE HEADER COUNTS THE STACK, NOT THE ROWS. `max-frames` trims the list in the report, before any
     * markup exists, so counting what is rendered would answer "7 of 40 in your code" for a stack of 104
     * and say nothing at all about the sixty-four frames that were dropped — a label that was honest
     * before the budget existed and became a quiet lie the moment it did. The untrimmed totals are carried
     * on the report for exactly this, and are named whenever they differ from what is shown; when nothing
     * was trimmed the "of" is left out, because "40 of 40" is a question a reader should not have to ask.
     */
    private static function frames(ErrorReport $report): string
    {
        if ($report->frames === []) {
            return '';
        }

        $own = [];
        $vendor = [];
        foreach ($report->frames as $frame) {
            if ($frame->vendor) {
                $vendor[] = $frame;
            } else {
                $own[] = $frame;
            }
        }

        $shown = count($report->frames);
        $summary = $shown === $report->frameCount
            ? $report->frameCount.' frames · '.$report->appFrameCount.' in your code'
            : $shown.' of '.$report->frameCount.' frames · '.$report->appFrameCount.' in your code';

        $html = '<section class="panel trace"><h2>Stack trace <span class="n">'.self::e($summary).'</span></h2>';

        if ($own !== []) {
            $html .= '<ol class="frames">';
            $opened = false;
            foreach ($own as $frame) {
                $html .= self::frame($frame, ! $opened && $frame->excerpt !== []);
                $opened = $opened || $frame->excerpt !== [];
            }
            $html .= '</ol>';
        }

        return $html.self::dependencies($vendor, $report->frameCount - $report->appFrameCount).'</section>';
    }

    /**
     * One application frame: a disclosure when there is source to disclose, a plain row when there is not.
     *
     * `name="firefly-frame"` is HTML's own exclusive accordion — opening one closes the rest, with no
     * JavaScript and no CSS — and `<summary>` is focusable and Enter/Space-operable because it is a real
     * control. A browser too old for the attribute simply lets several be open, which is the behaviour this
     * page had before and is not a failure.
     */
    private static function frame(ErrorFrame $frame, bool $open): string
    {
        if ($frame->excerpt === []) {
            // No body to expand into, so it renders as a row rather than as a control that does nothing.
            return '<li class="own"><div class="row">'.self::row($frame).'</div></li>';
        }

        return '<li class="own"><details name="firefly-frame"'.($open ? ' open' : '').'>'
            .'<summary>'.self::row($frame).'</summary>'
            .self::excerpt($frame).'</details></li>';
    }

    /**
     * A frame on ONE LINE, whatever the width.
     *
     * The order is the order a reader scans: where in the stack, whose code, which directory, WHICH FILE,
     * which line, what was called.
     *
     * WHAT THE ELLIPSIS TAKES, AND IN WHICH ORDER. `text-overflow:ellipsis` always drops the END of a span,
     * so the only way to say which token gets shortened first is to give each one a span of its own. Four
     * do: the directory, the file name, the line, and the call — split again into the qualifier and the
     * FUNCTION, `->get()`, because a Laravel trace is sixty `Illuminate\…` frames whose method names are
     * the only difference between them, and printing the call as one span clipped exactly that away: sixty
     * rows reading `Illuminate\Database\Eloq…`.
     *
     * With the spans in place the CSS ranks them, and the ranking is the whole design: `.dir` first —
     * its innermost directories are a real loss, but the file name, the line and the package badge beside
     * it are enough to find the file — then `.cls`, which repeats a class name the file name already gave,
     * and only then `.fn`. The file name and the line never shorten at all. What a rank does NOT mean is
     * "cannot shrink": a span that refuses to shrink in a row that must keeps its full width and paints
     * past the row's edge, where it is cut with no ellipsis at all, so every rank here is a shrink factor
     * and `.fn`'s is simply the smallest. On a phone even the last rank is not spent: the row wraps and
     * gives the call a line of its own rather than take a character off it.
     */
    private static function row(ErrorFrame $frame): string
    {
        $package = $frame->package();
        $qualifier = $frame->callQualifier();

        return '<span class="ix">'.self::e('#'.$frame->index).'</span>'
            .($package === null ? '' : '<span class="pkg">'.self::e($package).'</span>')
            .'<span class="dir">'.self::e($frame->dir()).'</span>'
            .'<span class="base">'.self::e($frame->base()).'</span>'
            .'<span class="ln">'.($frame->line === null ? '' : self::e(':'.$frame->line)).'</span>'
            .'<span class="call">'
            .($qualifier === '' ? '' : '<span class="cls">'.self::e($qualifier).'</span>')
            .'<span class="fn">'.self::e($frame->callFunction()).'</span>'
            .'</span>';
    }

    /**
     * Every dependency frame behind one disclosure, with an honest count of what the budget left out.
     *
     * @param  list<ErrorFrame>  $vendor
     * @param  int  $total  dependency frames in the UNTRIMMED stack
     */
    private static function dependencies(array $vendor, int $total): string
    {
        if ($vendor === []) {
            return '';
        }

        $label = $total.' frame'.($total === 1 ? '' : 's').' in your dependencies';
        $note = count($vendor) === $total ? '' : '<span class="dn">'.self::e(count($vendor).' shown').'</span>';

        $html = '<details class="deps"><summary><span class="dsum">'.self::e($label).'</span>'.$note.'</summary>'
            .'<ol class="frames deps-list">';

        foreach ($vendor as $frame) {
            $html .= '<li class="vendor"><div class="row">'.self::row($frame).'</div></li>';
        }

        return $html.'</ol></details>';
    }

    private static function excerpt(ErrorFrame $frame): string
    {
        $html = '<table class="src">';
        foreach ($frame->excerpt as $number => $text) {
            $hit = $number === $frame->line ? ' class="hit"' : '';
            $html .= '<tr'.$hit.'><td class="ln">'.self::e((string) $number).'</td><td class="ln-src">'.self::e($text).'</td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * The footer explains the page itself, and only where that is a safe thing to explain: on a
     * non-production environment. In production it says nothing — naming the framework and a config key to
     * an anonymous visitor is a free hint about the stack, and the error code above is the only thing that
     * page's reader actually needs.
     */
    private static function footer(ErrorReport $report, ErrorPageSettings $settings): string
    {
        if (! $settings->hints) {
            return '';
        }

        $note = $report->detailed
            ? 'Details are shown because <code>firefly.web.error-page.trace</code> is on (it follows <code>app.debug</code>). Turn either off and this page shows only the status and the code.'
            : 'Set <code>APP_DEBUG=true</code>, or <code>firefly.web.error-page.trace</code>, to see the exception and its stack trace here.';

        return '<footer class="foot"><p>'.$note.'</p>'
            .'<p class="muted">The same failure is served as <code>application/problem+json</code> to a client that asks for JSON.</p></footer>';
    }

    /**
     * A short, honest sentence for a production page — no message, no internals.
     *
     * The 5xx sentence names the request reference, because that is the ONE thing a reader of a production
     * page can do about a failure they cannot see: quote the id, so an operator can find the log line it
     * stamps. The problem document has said "quote reference <id>" since it carried `traceId`; the page a
     * person actually looks at said only that the error had been logged, which left them nothing to quote.
     * The wording mirrors ProblemMapper::OPAQUE_WITH_REFERENCE so a ticket reads the same whichever form
     * the failure was seen in.
     */
    private static function reassurance(int $status, string $reference): string
    {
        return match (true) {
            $status === 404 => 'That page does not exist.',
            $status === 403 => 'You do not have access to that.',
            $status === 401 => 'You need to sign in to see that.',
            $status === 405 => 'That address does not accept this kind of request.',
            $status >= 500 => $reference === ''
                ? 'Something went wrong on our side. The error has been logged.'
                : "Something went wrong on our side. It has been logged; quote reference {$reference} if you report it.",
            default => 'That request could not be completed.',
        };
    }

    private static function favicon(): string
    {
        return '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 32 32\'%3E%3Ccircle cx=\'16\' cy=\'16\' r=\'9\' fill=\'%23e07a17\'/%3E%3C/svg%3E">';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
:root{
  color-scheme:light;
  --bg:#f7f6f3; --panel:#fff; --panel-2:#faf9f6; --line:#e7e3db; --line-2:#d6d0c4;
  --ink:#20242a; --ink-2:#5f6672; --ink-3:#696f7d;
  --brand:#e07a17;
  /* The brand as TEXT. #e07a17 is a 3.01:1 foreground on white — fine for a 9px dot or a 3px rail, and
     unreadable for the exception class it was being used on. Shapes and text need different oranges. */
  --brand-ink:#a1520a;
  --down:#c02717; --down-bg:#fbe9e7; --warn:#9a6206; --warn-bg:#fdf1dd;
  --idle:#6b7280; --idle-bg:#f0f0f2;
  --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --r:12px;
}
@media (prefers-color-scheme: dark){
  :root{
    color-scheme:dark;
    --bg:#0f1214; --panel:#15191c; --panel-2:#181d21; --line:#252c32; --line-2:#333c44;
    --ink:#e8ecef; --ink-2:#9aa5af; --ink-3:#828e99;
    --brand:#ff9d3c;
    --brand-ink:#ff9d3c;
    --down:#ff8a7a; --down-bg:#2a1614; --warn:#ffc266; --warn-bg:#2a2114;
    --idle:#9aa5af; --idle-bg:#1c2226;
  }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 var(--sans);-webkit-font-smoothing:antialiased}
code{font-family:var(--mono);font-size:.92em;background:var(--panel-2);border:1px solid var(--line);border-radius:5px;padding:1px 5px}
.sheet{max-width:960px;margin:0 auto;padding:56px 20px 72px;display:flex;flex-direction:column;gap:22px;min-width:0}
.head{display:flex;flex-direction:column;gap:10px}
.mark{display:inline-flex;align-items:center;gap:9px;font-weight:650;letter-spacing:-.01em;color:var(--ink-2);margin-bottom:14px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--brand);flex:none;box-shadow:0 0 0 3px color-mix(in srgb, var(--brand) 18%, transparent)}
.status{display:flex;align-items:baseline;gap:12px;margin:0;flex-wrap:wrap}
.status b{font-size:64px;line-height:1;letter-spacing:-.04em;font-variant-numeric:tabular-nums}
.status span{font-size:19px;font-weight:600;color:var(--ink-2)}
.status.down b{color:var(--down)} .status.warn b{color:var(--warn)} .status.idle b{color:var(--idle)}
.code{margin:0;font-family:var(--mono);font-size:13px;letter-spacing:.04em;color:var(--ink-2)}
.message{margin:6px 0 0;font-size:16px;line-height:1.5;color:var(--ink);overflow-wrap:anywhere}
.muted{color:var(--ink-2)}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1px;margin:0;background:var(--line);border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
.facts>div{background:var(--panel);padding:11px 14px;min-width:0}
.facts dt{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-2);margin:0 0 3px}
.facts dd{margin:0;font-family:var(--mono);font-size:12.5px;overflow-wrap:anywhere}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;min-width:0}
.panel h2{margin:0;padding:12px 16px;font-size:13px;font-weight:650;border-bottom:1px solid var(--line);background:var(--panel-2);display:flex;justify-content:space-between;gap:12px;align-items:baseline}
.panel h2 .n{font-weight:400;font-size:11.5px;color:var(--ink-3);font-family:var(--mono)}
.chain{list-style:none;margin:0;padding:0}
.chain li{padding:12px 16px;border-bottom:1px solid var(--line)}
.chain li:last-child{border-bottom:0}
.chain .cls{margin:0;font-family:var(--mono);font-size:12.5px;color:var(--brand-ink);overflow-wrap:anywhere}
.chain .msg{margin:3px 0 0;overflow-wrap:anywhere}
.chain .loc{margin:3px 0 0;font-family:var(--mono);font-size:12px;color:var(--ink-3);overflow-wrap:anywhere}
.frames{list-style:none;margin:0;padding:0}
.frames li{border-bottom:1px solid var(--line)}
.frames li:last-child{border-bottom:0}
/* ONE LINE PER FRAME. The old rule was flex-wrap:wrap with overflow-wrap:anywhere on the path and
   margin-left:auto on the call, so every row became a wrapped path plus a third line for the call — an
   87px pitch that made a hundred-frame trace 10,108 pixels tall. Only .dir and .cls may shrink. */
.frames .row,.frames summary{display:flex;gap:8px;align-items:baseline;padding:7px 16px;min-width:0;flex-wrap:nowrap;white-space:nowrap}
.frames summary{cursor:pointer;list-style:none}
.frames summary::-webkit-details-marker{display:none}
.frames summary::before{content:"▸";color:var(--ink-3);font-size:10px;flex:none}
.frames details[open] summary::before{content:"▾"}
/* THE RING IS DRAWN IN --brand-ink, NOT --brand. A focus indicator is a non-text contrast target: WCAG
   2.1 SC 1.4.11 asks for 3:1 against what it sits on, and #e07a17 measures 3.01:1 on a white panel and
   2.86:1 on --panel-2, which is exactly where the dependency disclosure's ring is drawn. Passing by 0.01
   on one ground and failing on the other is not a contrast decision, it is an accident. --brand-ink is
   the token this file already keeps for the brand as a foreground: 5.63:1 and 5.35:1 on those two grounds
   in light, and identical to --brand in dark, where both are #ff9d3c. */
.frames summary:focus-visible{outline:2px solid var(--brand-ink);outline-offset:-2px}
.frames .ix{font-family:var(--mono);font-size:11px;color:var(--ink-3);flex:none;min-width:2.8em;text-align:right}
.frames .pkg{font-size:11px;line-height:1.7;color:var(--ink-2);background:var(--panel-2);border:1px solid var(--line);border-radius:5px;padding:0 5px;flex:none;max-width:13em;overflow:hidden;text-overflow:ellipsis}
.frames .dir{font-family:var(--mono);font-size:12.5px;color:var(--ink-2);min-width:0;flex:0 100 auto;overflow:hidden;text-overflow:ellipsis}
.frames .base{font-family:var(--mono);font-size:12.5px;color:var(--ink);flex:none}
.frames .ln{font-family:var(--mono);font-size:12.5px;color:var(--ink-2);flex:none}
/* The call is the path's twin: .cls is the qualifier and may be ellipsised, .fn is the method name and is
   the token that tells sixty Illuminate frames apart, so it is the LAST thing the row gives up. A nested
   flex rather than two row items, so the pair stays glued (the row's 8px gap would print
   `Collection ->each()`).

   WHAT "LAST" IS MADE OF, because `flex:none` did not mean it. An unshrinkable span inside a shrinking row
   does not stay WHOLE, it stays WIDE: its box keeps its content width, its own `text-overflow` therefore
   never has a narrower box to draw an ellipsis in, and the glyphs simply run out of the row and are cut by
   `.panel{overflow:hidden}` with nothing to mark the cut. Measured in Chrome at 375px against a 35-frame
   Laravel trace, 34 of 35 rows painted their method name up to 138px beyond the panel's right edge, and
   `->whereHasMorphRelationship` arrived on screen as `->wh`.
   So the ORDER is stated with shrink factors rather than by refusing to shrink: .dir and .cls shrink at
   100 and .fn at 1, which spends the two discardable spans down to nothing before flexbox takes a single
   character off the method name — and `overflow:hidden` here means that whatever is taken is taken inside
   the row, with an ellipsis, instead of outside it in silence. At a 560-920px panel that ranking leaves
   every method name whole except the closure descriptor below. */
.frames .call{font-family:var(--mono);font-size:12px;color:var(--ink-3);margin-left:auto;min-width:0;flex:0 1 auto;display:flex;align-items:baseline;overflow:hidden}
.frames .call .cls{min-width:0;flex:0 100 auto;overflow:hidden;text-overflow:ellipsis}
/* The cap is the one guard: PHP 8.4 names a closure `{closure:/abs/path/file.php:14}`, so a "function
   name" can be a hundred characters of absolute path. It sits far above any real method name and bites
   only that case, which would otherwise take the whole row's width for one frame's descriptor. */
.frames .call .fn{min-width:0;flex:0 1 auto;max-width:24em;overflow:hidden;text-overflow:ellipsis}
/* The application's own frames are the point of the page; the dependency ones are context. */
.frames li.own{border-left:3px solid var(--brand);background:var(--panel)}
.frames li.own .base{font-weight:600}
.frames li.vendor{border-left:3px solid transparent;background:var(--panel-2)}
/* De-emphasised by weight, ground and the missing rail — NOT by fading text below readable contrast. */
.frames li.vendor .base{font-weight:400;color:var(--ink-2)}
.deps{border-top:1px solid var(--line);background:var(--panel-2)}
.deps>summary{display:flex;gap:12px;align-items:baseline;padding:10px 16px;cursor:pointer;list-style:none;font-size:12.5px;color:var(--ink-2)}
.deps>summary::-webkit-details-marker{display:none}
.deps>summary::before{content:"▸";color:var(--ink-3);font-size:10px}
.deps[open]>summary::before{content:"▾"}
/* The one control a keyboard reader MUST operate to reach the dependency frames, on --panel-2. */
.deps>summary:focus-visible{outline:2px solid var(--brand-ink);outline-offset:-2px}
.deps .dn{margin-left:auto;font-family:var(--mono);font-size:11.5px;color:var(--ink-3)}
.deps-list{border-top:1px solid var(--line)}
.src{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:12.5px;background:var(--panel-2);border-top:1px solid var(--line);display:block;overflow-x:auto}
.src tr{display:table;width:100%;table-layout:fixed}
.src td{padding:2px 10px;white-space:pre;vertical-align:top}
.src .ln{width:56px;text-align:right;color:var(--ink-2);user-select:none;border-right:1px solid var(--line)}
.src .ln-src{overflow-wrap:normal}
.src tr.hit{background:color-mix(in srgb, var(--brand) 14%, transparent)}
.src tr.hit .ln{color:var(--brand-ink);font-weight:700}
.foot{color:var(--ink-2);font-size:12.5px;display:flex;flex-direction:column;gap:5px}
.foot p{margin:0}
@media (max-width:560px){
  .sheet{padding:32px 14px 48px}
  .status b{font-size:48px}
  .frames .pkg{display:none}
  /* The qualifier goes entirely, before the method name loses a character: on a phone row
     `Illuminate\Database\Eloquent\Builder` is what `Builder.php` two columns to its left already said,
     and `->get()` is not said anywhere else. */
  .frames .call .cls{display:none}
  /* AND THEN THE PHONE ROW WRAPS, because at 375px it provably cannot do both. A row has 295px there, and
     `AddQueuedCookiesToResponse.php` — a real Laravel file name, which never shortens — is 226 of them;
     `:46` and `->handle` have to go somewhere. Ranked shrinking has an answer for that and it is the wrong
     one: measured at 375px it spent .fn down until 23 of 35 method names had lost characters and two had
     lost all of them, rendering at zero width. So the phone takes the other branch — one more line instead
     of a shorter name — and the directory goes with .pkg and .cls, because it was already ellipsised to
     `vendor/la…` on every row at this width and dropping it is what holds the wrapped row to two lines
     instead of the four a full-width .dir forces (measured: 1,961px of trace against 3,406px).
     This is NOT the pre-wave rule that made a hundred frames 10,108 pixels tall. That one wrapped the PATH
     ITSELF, with overflow-wrap:anywhere, at 87px a row; nothing here wraps inside a span, and the break
     can only fall between two whole tokens. */
  .frames .dir{display:none}
  .frames .row,.frames summary{flex-wrap:wrap}
  .frames .call{margin-left:0}
  .frames .ix{min-width:2.2em}
  /* The call keeps the 24em guard it has everywhere and no tighter one. A phone used to cap it at 14em,
     which was the right cap while the call had to share a line with a file name and was the wrong one the
     moment it stopped: on its own 295px line, 14em cut `->sendRequestThroughRouter` and
     `->whereHasMorphRelationship` for nothing. 24em still fits that line, and still bites the closure
     descriptor it exists for. */
}
CSS;
    }
}
