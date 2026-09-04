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
 * THE SIGNATURE IS THE TRACE, because that is what the page is FOR. A raw PHP trace is forty frames of which
 * four are yours; here the application's frames carry the accent rail and open by default with their source
 * excerpt, and the vendor frames collapse to one dim line each. That distinction is the entire difference
 * between scrolling a trace and reading one, and it is drawn with `<details>` and CSS — no JavaScript, so it
 * works with scripts disabled and in whatever a container's minimal browser turns out to be.
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
            $html .= '<p class="message muted">'.self::e(self::reassurance($report->status)).'</p>';
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
        ];

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

    private static function frames(ErrorReport $report): string
    {
        if ($report->frames === []) {
            return '';
        }

        $app = 0;
        foreach ($report->frames as $frame) {
            if (! $frame->vendor) {
                $app++;
            }
        }

        $html = '<section class="panel"><h2>Stack trace <span class="n">'
            .self::e((string) $app).' of '.self::e((string) count($report->frames)).' in your code</span></h2><ol class="frames">';

        $opened = 0;
        foreach ($report->frames as $frame) {
            $html .= self::frame($frame, $opened);
        }

        return $html.'</ol></section>';
    }

    private static function frame(ErrorFrame $frame, int &$opened): string
    {
        $where = $frame->shortFile.($frame->line === null ? '' : ':'.$frame->line);
        $summary = '<summary><span class="where">'.self::e($where).'</span>'
            .'<span class="call">'.self::e($frame->call).'</span></summary>';

        if ($frame->excerpt === []) {
            // No body to expand into, so it renders as a plain row rather than as a control that does
            // nothing when clicked.
            return '<li class="'.($frame->vendor ? 'vendor' : 'own').'"><div class="row">'
                .'<span class="where">'.self::e($where).'</span>'
                .'<span class="call">'.self::e($frame->call).'</span></div></li>';
        }

        // The first two frames with source are opened; past that the page becomes a wall of code and the
        // reader loses the shape of the stack.
        $open = $opened < 2 ? ' open' : '';
        $opened++;

        return '<li class="own"><details'.$open.'>'.$summary.self::excerpt($frame).'</details></li>';
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

    /** A short, honest sentence for a production page — no message, no internals. */
    private static function reassurance(int $status): string
    {
        return match (true) {
            $status === 404 => 'That page does not exist.',
            $status === 403 => 'You do not have access to that.',
            $status === 401 => 'You need to sign in to see that.',
            $status === 405 => 'That address does not accept this kind of request.',
            $status >= 500 => 'Something went wrong on our side. The error has been logged.',
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
  --ink:#20242a; --ink-2:#5f6672; --ink-3:#8d95a1;
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
    --ink:#e8ecef; --ink-2:#9aa5af; --ink-3:#6c7883;
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
.frames{list-style:none;margin:0;padding:0;counter-reset:f}
.frames li{border-bottom:1px solid var(--line)}
.frames li:last-child{border-bottom:0}
.frames .row,.frames summary{display:flex;gap:14px;align-items:baseline;padding:8px 16px;min-width:0;flex-wrap:wrap}
.frames summary{cursor:pointer;list-style:none}
.frames summary::-webkit-details-marker{display:none}
.frames summary::before{content:"▸";color:var(--ink-3);font-size:10px;margin-right:-6px}
.frames details[open] summary::before{content:"▾"}
.frames .where{font-family:var(--mono);font-size:12.5px;overflow-wrap:anywhere}
.frames .call{font-family:var(--mono);font-size:12px;color:var(--ink-3);overflow-wrap:anywhere;margin-left:auto}
/* The application's own frames are the point of the page; the vendor ones are context. */
.frames li.own{border-left:3px solid var(--brand);background:var(--panel)}
.frames li.own .where{color:var(--ink);font-weight:600}
.frames li.vendor{border-left:3px solid transparent;background:var(--panel-2)}
/* De-emphasised by weight, ground and the missing rail — NOT by fading the text below readable contrast.
   A vendor frame's path is still the thing a reader came for once they have ruled their own code out. */
.frames li.vendor .where{color:var(--ink-2);font-weight:400}
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
  .frames .call{margin-left:0;width:100%}
}
CSS;
    }
}
