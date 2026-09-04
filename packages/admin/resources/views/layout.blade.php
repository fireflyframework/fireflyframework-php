{{--
    The dashboard shell.

    Inline CSS and system fonts on purpose: a composer package cannot assume npm has run, and a dashboard
    that needs a CDN at request time is useless in exactly the network-isolated environments where you most
    want to look at one.

    The chrome is deliberately NEUTRAL. Colour here means status — green is up, red is down, amber is
    attention — so spending it on decoration would make a failing indicator compete with a heading for the
    eye. The brand appears once, as the dot in the wordmark.
--}}
<!DOCTYPE html>
<html lang="en" data-theme="{{ $settings->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · {{ $settings->title }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='9' fill='%23ff9d3c'/%3E%3C/svg%3E">
    <style>
        :root {
            color-scheme: light;
            --bg:#f7f6f3;
            --shell:#ffffff;
            --panel:#ffffff;
            --panel-2:#faf9f6;
            --hover:#f4f2ed;
            --line:#e7e3db;
            --line-2:#d6d0c4;

            --ink:#20242a;
            --ink-2:#5f6672;
            /*
             | THE TERTIARY INK IS A READABLE COLOUR, not a faded one. It was #8d95a1, which is 2.8:1 on
             | this page's own background — below WCAG AA for normal text — and it is not decorative: it
             | paints table cells (.dim), the explanatory notes under every panel, the uppercase stat
             | labels and the namespace half of every class name. Those are content. The hierarchy between
             | --ink-2 and --ink-3 is now carried by size, weight and position, which is where it belonged;
             | you cannot buy hierarchy with illegibility.
            */
            --ink-3:#667181;

            --brand:#e07a17;
            --accent:#0f62c9;
            --accent-soft:#e8f0fc;

            --up:#0f7a44;      --up-bg:#e6f4ec;
            --down:#c02717;    --down-bg:#fbe9e7;
            --warn:#9a6206;    --warn-bg:#fdf1dd;
            --idle:#6b7280;    --idle-bg:#f0f0f2;

            --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
            --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;

            --rail:224px;
            --top:52px;
            --r:10px;
            --shadow:0 1px 2px rgba(24,20,12,.05), 0 6px 18px -12px rgba(24,20,12,.25);
        }
        html[data-theme="dark"], html[data-theme="auto"] .dark-probe { }
        @media (prefers-color-scheme: dark) {
            html[data-theme="auto"] {
                color-scheme: dark;
                --bg:#0f1214;
                --shell:#15191c;
                --panel:#15191c;
                --panel-2:#191e22;
                --hover:#1d2328;
                --line:#252c32;
                --line-2:#333c44;

                --ink:#e8ecef;
                --ink-2:#9aa5af;
                --ink-3:#8592a0;

                --brand:#ff9d3c;
                --accent:#5da2ff;
                --accent-soft:#15263c;

                --up:#5cc98c;      --up-bg:#12271c;
                --down:#f2796a;    --down-bg:#2b1613;
                --warn:#e9b25c;    --warn-bg:#2a2013;
                --idle:#8b949e;    --idle-bg:#1c2126;

                --shadow:0 1px 2px rgba(0,0,0,.5), 0 8px 24px -14px rgba(0,0,0,.9);
            }
        }
        html[data-theme="dark"] {
            color-scheme: dark;
            --bg:#0f1214;
            --shell:#15191c;
            --panel:#15191c;
            --panel-2:#191e22;
            --hover:#1d2328;
            --line:#252c32;
            --line-2:#333c44;

            --ink:#e8ecef;
            --ink-2:#9aa5af;
            --ink-3:#8592a0;

            --brand:#ff9d3c;
            --accent:#5da2ff;
            --accent-soft:#15263c;

            --up:#5cc98c;      --up-bg:#12271c;
            --down:#f2796a;    --down-bg:#2b1613;
            --warn:#e9b25c;    --warn-bg:#2a2013;
            --idle:#8b949e;    --idle-bg:#1c2126;

            --shadow:0 1px 2px rgba(0,0,0,.5), 0 8px 24px -14px rgba(0,0,0,.9);
        }

        *,*::before,*::after{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0;background:var(--bg);color:var(--ink);
            font:14px/1.55 var(--sans);
            -webkit-font-smoothing:antialiased;
        }
        a{color:var(--accent);text-decoration:none}
        a:hover{text-decoration:underline;text-underline-offset:2px}
        :focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}
        button{font:inherit}

        /* ── shell ───────────────────────────────────────────────────────── */
        .app{display:grid;grid-template-columns:var(--rail) minmax(0,1fr);grid-template-rows:var(--top) minmax(0,1fr);height:100%}
        @media(max-width:900px){
            .app{grid-template-columns:1fr;grid-template-rows:var(--top) auto minmax(0,1fr)}
        }

        .topbar{
            /*
             | min-width:0 is load-bearing, not tidiness. A grid item defaults to min-width:auto, which
             | refuses to size below its own min-content — so at 320px the bar grew to 398px and took the
             | document's horizontal scrollbar with it, while its flex children sat at their natural widths
             | and never shrank, because the CONTAINER had absorbed the pressure instead of passing it on.
             | With this, the pressure reaches .topchips, which is the child built to give.
            */
            grid-column:1 / -1;min-width:0;display:flex;align-items:center;gap:14px;
            padding:0 16px;background:var(--shell);border-bottom:1px solid var(--line);
        }
        .wordmark{display:flex;align-items:center;gap:9px;font-weight:650;letter-spacing:-.01em;white-space:nowrap;flex:none}
        .dot{width:9px;height:9px;border-radius:50%;background:var(--brand);flex:none;box-shadow:0 0 0 3px color-mix(in srgb, var(--brand) 18%, transparent)}
        .wordmark small{color:var(--ink-3);font-weight:400;font-size:11.5px;font-family:var(--mono)}
        .topbar .spacer{flex:1}

        /*
         | A page's own chips are the ONE part of the bar that varies in width, so they are the one part
         | allowed to shrink and scroll. Everything else in here — the wordmark, Auto, Theme — is fixed and
         | must stay reachable: a phone-width overview page pushed the whole bar 39px past the viewport and
         | took the document's horizontal scrollbar with it, because every child was nowrap and none of them
         | would give. min-width:0 is what actually lets a flex item shrink below its content.
        */
        .topchips{display:flex;align-items:center;gap:10px;min-width:0;overflow-x:auto;scrollbar-width:none}
        .topchips::-webkit-scrollbar{display:none}

        /* Below this the two words plus two buttons genuinely do not fit; the suffix is the redundant one. */
        @media(max-width:520px){
            .topbar{gap:10px;padding:0 12px}
            .wordmark small{display:none}
        }

        .chip{
            display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 9px;border-radius:999px;
            font-size:11.5px;font-weight:600;letter-spacing:.02em;white-space:nowrap;
            background:var(--idle-bg);color:var(--idle);
        }
        .chip::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;flex:none}
        .chip.up{background:var(--up-bg);color:var(--up)}
        .chip.down{background:var(--down-bg);color:var(--down)}
        .chip.warn{background:var(--warn-bg);color:var(--warn)}
        .chip.flat{background:transparent;color:var(--ink-3);border:1px solid var(--line-2);font-family:var(--mono);font-weight:500}
        .chip.flat::before{display:none}

        .tool{
            display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border-radius:7px;
            border:1px solid var(--line-2);background:transparent;color:var(--ink-2);cursor:pointer;
            font-size:12px;white-space:nowrap;
        }
        .tool:hover{background:var(--hover);color:var(--ink)}
        .tool[aria-pressed="true"]{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}
        .tool .tick{font-family:var(--mono);font-variant-numeric:tabular-nums;font-size:11px;min-width:18px;text-align:right}

        /* ── rail ────────────────────────────────────────────────────────── */
        .rail{
            background:var(--shell);border-right:1px solid var(--line);
            overflow-y:auto;padding:14px 0 24px;
        }
        @media(max-width:900px){
            .rail{border-right:0;border-bottom:1px solid var(--line);padding:10px 12px;overflow:visible}
        }
        .rail .group{padding:0 14px;margin:14px 0 5px;font-size:10px;font-weight:700;letter-spacing:.14em;
            text-transform:uppercase;color:var(--ink-3)}
        .rail .group:first-child{margin-top:0}
        @media(max-width:900px){.rail .group{display:none}}
        nav.links{display:flex;flex-direction:column}
        @media(max-width:900px){nav.links{flex-direction:row;flex-wrap:wrap;gap:4px}}
        nav.links a{
            display:flex;align-items:center;justify-content:space-between;gap:8px;
            padding:6px 14px;color:var(--ink-2);font-size:13.5px;border-left:2px solid transparent;
        }
        @media(max-width:900px){nav.links a{border-left:0;border-radius:6px;padding:5px 9px;font-size:13px}}
        nav.links a:hover{background:var(--hover);color:var(--ink);text-decoration:none}
        nav.links a[aria-current]{border-left-color:var(--accent);color:var(--ink);font-weight:600;background:var(--hover)}
        nav.links .n{font-family:var(--mono);font-size:11px;color:var(--ink-3);font-variant-numeric:tabular-nums}

        /* ── content ─────────────────────────────────────────────────────── */
        main{overflow-y:auto;padding:22px clamp(16px,2.4vw,28px) 56px;min-width:0}
        .head{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:baseline;margin-bottom:18px}
        h1{margin:0;font-size:19px;font-weight:650;letter-spacing:-.015em}
        .head p{margin:0;color:var(--ink-2);font-size:13.5px;flex:1 1 30ch;min-width:0}

        /* ── panels ──────────────────────────────────────────────────────── */
        .panel{border:1px solid var(--line);border-radius:var(--r);background:var(--panel);overflow:hidden;box-shadow:var(--shadow)}
        .panel + .panel{margin-top:16px}
        /* Inside a grid the gap already spaces siblings; the stacking margin would push the second column
           down by 16px and leave the two panel headers visibly out of line. */
        .grid > .panel + .panel{margin-top:0}
        .panel > header{
            display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid var(--line);
            background:var(--panel-2);
        }
        .panel > header h2{margin:0;font-size:11px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--ink-3)}
        .panel > header .spacer{flex:1}
        .panel > header .meta{font-family:var(--mono);font-size:11.5px;color:var(--ink-3);font-variant-numeric:tabular-nums}

        /* align-items:start so a short panel does not stretch to match a tall neighbour and leave a void
           under its own content — the single biggest source of dead space on a wide screen. */
        .grid{display:grid;gap:16px;align-items:start}
        .grid.two{grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr))}
        .grid.three{grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr))}
        /* A dashboard on a 1920 screen should show MORE, not the same two panels stretched to 840px each.
           auto-fit only creates as many tracks as there are children, so the overview supplies enough
           panels to fill them. */
        @media(min-width:1500px){
            .grid.two{grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr))}
        }

        /* ── stat strip ──────────────────────────────────────────────────── */
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));
               border:1px solid var(--line);border-radius:var(--r);background:var(--panel);overflow:hidden;box-shadow:var(--shadow)}
        .stat{padding:11px 14px;border-right:1px solid var(--line);min-width:0}
        .stat:last-child{border-right:0}
        .stat dt{font-size:10px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--ink-3);
                 white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:5px}
        .stat dd{margin:0;font-family:var(--mono);font-size:20px;font-weight:600;line-height:1.2;
                 font-variant-numeric:tabular-nums;letter-spacing:-.02em;display:flex;align-items:center;min-height:26px}
        .stat dd small{font-size:12px;color:var(--ink-3);font-weight:400;margin-left:3px}
        .stat a{color:inherit}

        /* ── tables ──────────────────────────────────────────────────────── */
        .tw{overflow-x:auto}
        table{border-collapse:collapse;width:100%;font-size:13px}
        thead th{
            position:sticky;top:0;z-index:1;text-align:left;padding:8px 14px;background:var(--panel-2);
            border-bottom:1px solid var(--line);color:var(--ink-3);
            font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;white-space:nowrap;
        }
        tbody td{padding:8px 14px;border-bottom:1px solid var(--line);vertical-align:top}
        tbody tr:last-child td{border-bottom:0}
        tbody tr:hover{background:var(--hover)}
        td.mono,th.mono{font-family:var(--mono);font-size:12.5px}
        td.num{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
        td.tight{width:1%;white-space:nowrap}
        .dim{color:var(--ink-3)}
        .wrap{overflow-wrap:anywhere}
        /* Long free-text cells stop growing past a readable measure instead of stretching the row to the
           full window width, which on a 1920 screen put a label at x=270 and its value at x=1855. */
        td.text{max-width:64ch}
        td.num.pin{width:1%}

        /* A fully-qualified class name has no spaces, so overflow-wrap:anywhere breaks it mid-word —
           "SecurityHeadersFilte / r". Showing the short name on its own line and eliding the namespace under
           it keeps the column scannable: you read class names down the page instead of decoding wraps. */
        /* max-width:0 with width:100% is the standard way to make ONE table cell absorb the slack and
           truncate, instead of the longest class name widening the table until the columns beside it are
           pushed off the panel — which is what clipped the condition column. */
        .cls{max-width:0;width:100%}
        .cls .nm,.cls .ns{display:block;font-family:var(--mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .cls .nm{font-size:12.5px}
        .cls .ns{font-size:11px;color:var(--ink-3)}

        /* ── verb + status pills ─────────────────────────────────────────── */
        .verb{
            display:inline-block;min-width:46px;text-align:center;font-family:var(--mono);font-size:10.5px;
            font-weight:700;letter-spacing:.06em;padding:2px 6px;border-radius:5px;
            background:var(--accent-soft);color:var(--accent);
        }
        .code{font-family:var(--mono);font-size:11.5px;font-weight:700;padding:2px 7px;border-radius:5px}
        .code.ok{background:var(--up-bg);color:var(--up)}
        .code.warn{background:var(--warn-bg);color:var(--warn)}
        .code.err{background:var(--down-bg);color:var(--down)}

        /* ── bar ─────────────────────────────────────────────────────────── */
        .bar{height:5px;border-radius:3px;background:var(--line);overflow:hidden;min-width:60px}
        .bar > i{display:block;height:100%;background:var(--accent);border-radius:3px}
        .bar.good > i{background:var(--up)}
        .bar.warn > i{background:var(--warn)}

        /* ── filter ──────────────────────────────────────────────────────── */
        .filter{
            width:min(320px,100%);font:13px var(--sans);height:28px;padding:0 10px;border-radius:7px;
            border:1px solid var(--line-2);background:var(--bg);color:var(--ink);
        }
        .filter::placeholder{color:var(--ink-3)}

        /* ── empty ───────────────────────────────────────────────────────── */
        .empty{padding:26px 16px;text-align:center;color:var(--ink-2)}
        .empty strong{display:block;font-size:14px;color:var(--ink);margin-bottom:4px;font-weight:600}
        .empty p{margin:0;font-size:13px;max-width:56ch;margin-inline:auto}
        .note{margin:12px 0 0;font-size:12.5px;color:var(--ink-3);max-width:80ch}
        .note{padding:0 16px 14px}
        .panel>.note:first-child{padding-top:14px}

        /* A settings row is a bag of small key/value facts, not a table of its own — a nested table for
           `charset: utf8mb4` would be four times the markup to say the same thing, and would not wrap. */
        .pair{display:inline-flex;align-items:baseline;gap:5px;margin:0 8px 4px 0;font-family:var(--mono);font-size:11.5px;white-space:nowrap}
        .pair b{font-weight:600;color:var(--ink-3)}
        .pair.opt b{color:var(--accent)}
        .stat dd.sm{font-size:14px;word-break:break-all}

        code{font-family:var(--mono);font-size:.9em;background:var(--hover);color:var(--ink-2);
             padding:1px 5px;border-radius:4px;border:1px solid var(--line)}

        select,.act{
            font:12.5px var(--sans);height:26px;padding:0 8px;border-radius:6px;
            border:1px solid var(--line-2);background:var(--panel);color:var(--ink);cursor:pointer;
        }
        .act:hover{border-color:var(--accent);color:var(--accent)}

        /* ── bean graph ──────────────────────────────────────────────────── */
        .legend{display:flex;flex-wrap:wrap;gap:6px;padding:11px 14px;border-bottom:1px solid var(--line);background:var(--panel-2)}
        .mod{
            display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 9px;border-radius:999px;
            border:1px solid var(--line-2);background:transparent;color:var(--ink-2);cursor:pointer;
            font-family:var(--mono);font-size:11px;
        }
        .mod i{width:8px;height:8px;border-radius:2px;background:hsl(var(--hue) 62% 46%);flex:none}
        .mod:hover{border-color:var(--ink-3);color:var(--ink)}
        .mod[aria-pressed="false"]{opacity:.42;text-decoration:line-through}

        .graph{display:grid;grid-template-columns:minmax(0,1fr) 268px}
        @media(max-width:1100px){.graph{grid-template-columns:1fr}}
        .graph .canvas{position:relative;height:min(72vh,720px);overflow:hidden;background:var(--panel-2);cursor:grab;touch-action:none}
        .graph .canvas.grabbing{cursor:grabbing}
        .graph .canvas svg{width:100%;height:100%;display:block}
        .hint-bar{
            position:absolute;left:10px;bottom:8px;font-size:11px;color:var(--ink-3);
            background:color-mix(in srgb, var(--panel) 84%, transparent);padding:3px 8px;border-radius:6px;
            pointer-events:none;
        }
        .inspect{border-left:1px solid var(--line);padding:12px 14px;overflow:hidden auto;height:min(72vh,720px);background:var(--panel);min-width:0}
        @media(max-width:1100px){.inspect{border-left:0;border-top:1px solid var(--line);height:auto;max-height:320px}}
        .inspect .blank{color:var(--ink-3);font-size:13px;padding:18px 0}
        .inspect .who{margin-bottom:12px}
        .inspect .who strong{display:block;font-size:14.5px}
        .inspect .who code{display:block;margin-top:4px;font-size:10.5px;overflow-wrap:anywhere;background:none;border:0;padding:0;color:var(--ink-3)}
        .inspect h4{margin:12px 0 5px;font-size:10px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--ink-3)}
        .inspect h4 span{color:var(--ink-2);letter-spacing:0}
        .inspect ul{list-style:none;margin:0;padding:0}
        .inspect li button{
            display:block;width:100%;text-align:left;background:none;border:0;padding:3px 0;cursor:pointer;
            font-family:var(--mono);font-size:11.5px;color:var(--accent);
            overflow-wrap:anywhere;line-height:1.4;
        }
        .inspect li button:hover{text-decoration:underline}
        .inspect .none{margin:0;font-size:12.5px;color:var(--ink-3)}
        .canvas{overflow:auto;padding:14px;background:var(--panel-2);max-height:70vh}
        .canvas svg{display:block;margin-inline:auto}
        .edges .edge{fill:none;stroke:var(--line-2);stroke-width:1.3;color:var(--line-2);transition:stroke .12s,opacity .12s}
        .edges .edge.via{stroke-dasharray:4 3}
        /* A `produces` edge is structure, not a dependency the author wrote — drawn quieter so the wiring
           the reader came to see stays the loudest thing on the canvas. */
        .edges .edge.produces{stroke-dasharray:1 4;opacity:.55}
        .edges .edge.lit{stroke:var(--accent);color:var(--accent);stroke-width:2;opacity:1}
        .edges .edge.dimmed{opacity:.08}
        .edges .edge.off{display:none}

        .nodes .node{cursor:pointer}
        .nodes .node rect{fill:var(--panel);stroke:var(--line-2);stroke-width:1.1;transition:stroke .12s,fill .12s}
        .nodes .node .stripe{fill:hsl(var(--hue) 62% 46%);stroke:none}
        .nodes .node text{font-family:var(--mono);font-size:11px;fill:var(--ink);pointer-events:none}
        .nodes .node text.sub{font-size:9.5px;fill:var(--ink-3)}
        /* A #[Bean] product is a value a factory returns, not a class the scanner found — dashed says so. */
        .nodes .node.k-bean rect{stroke-dasharray:3 2}
        .nodes .node.k-config rect{fill:var(--hover)}
        .nodes .node:hover rect,.nodes .node.lit rect{stroke:var(--accent);fill:var(--accent-soft)}
        .nodes .node.picked rect{stroke:var(--accent);stroke-width:2}
        .nodes .node.dimmed{opacity:.22}
        .nodes .node.off{display:none}
        .nodes .node:focus-visible rect{stroke:var(--accent);stroke-width:2}

        /* ── data browser ────────────────────────────────────────────────── */
        .pager{display:flex;align-items:center;gap:10px;padding:10px 14px;border-top:1px solid var(--line);
               font-size:12.5px;color:var(--ink-2)}
        .pager .spacer{flex:1}
        .pager .act{text-decoration:none;display:inline-flex;align-items:center}
        .editor{padding:14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
        .editor label{display:flex;flex-direction:column;gap:4px;min-width:0}
        .editor label span{font-size:12px;color:var(--ink-2)}
        .editor label em{font-style:normal;font-family:var(--mono);font-size:10.5px;color:var(--ink-3)}
        .editor input{
            font:12.5px var(--mono);height:30px;padding:0 9px;border-radius:7px;
            border:1px solid var(--line-2);background:var(--bg);color:var(--ink);min-width:0;
        }
        .editor .actions{grid-column:1/-1;display:flex;gap:8px}
        .go{height:30px;padding:0 14px;border-radius:7px;border:1px solid var(--accent);background:var(--accent);
            color:#fff;cursor:pointer;font-size:13px;font-weight:600}
        .go:hover{filter:brightness(1.08)}
        .act.danger{color:var(--down);border-color:var(--down)}
        .act.danger:hover{background:var(--down-bg)}
        .tip{margin:0 0 16px;border-radius:8px;background:var(--accent-soft);color:var(--ink);
             padding:11px 14px;font-size:13.5px}

        [hidden]{display:none!important}
    </style>
</head>
<body>
<div class="app">
    <div class="topbar">
        <span class="wordmark"><span class="dot" aria-hidden="true"></span>{{ $settings->title }}<small>admin</small></span>
        @hasSection('topchips')<span class="topchips">@yield('topchips')</span>@endif
        <span class="spacer"></span>
        <button class="tool" type="button" id="refresh" aria-pressed="false" title="Reload this page every 10 seconds">
            <span>Auto</span><span class="tick" id="tick"></span>
        </button>
        <button class="tool" type="button" id="theme" title="Switch between light and dark">Theme</button>
    </div>

    <aside class="rail">
        @foreach ($groups as $group)
            @php $items = array_values(array_filter($nav, fn ($p) => $p->group === $group)); @endphp
            @if ($items !== [])
                <div class="group">{{ $group }}</div>
                <nav class="links">
                    @foreach ($items as $item)
                        <a href="{{ $settings->url($item->slug) }}" @if ($item->slug === $active) aria-current="page" @endif>
                            <span>{{ $item->label }}</span>
                            @isset($counts[$item->slug])<span class="n">{{ $counts[$item->slug] }}</span>@endisset
                        </a>
                    @endforeach
                </nav>
            @endif
        @endforeach
    </aside>

    <main>
        @yield('body')
    </main>
</div>

<script>
    (function () {
        // Theme: three states, matching how the rest of the framework's pages behave — follow the OS by
        // default, and remember an explicit choice. Written before paint would be better, but a package
        // view cannot inject into <head> without a build step, and the flash is one frame.
        var root = document.documentElement;
        var stored = null;
        try { stored = localStorage.getItem('firefly-admin-theme'); } catch (e) { /* private mode */ }
        if (stored === 'dark' || stored === 'light') { root.setAttribute('data-theme', stored); }

        document.getElementById('theme').addEventListener('click', function () {
            var dark = root.getAttribute('data-theme') === 'dark'
                || (root.getAttribute('data-theme') === 'auto'
                    && window.matchMedia('(prefers-color-scheme: dark)').matches);
            var next = dark ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('firefly-admin-theme', next); } catch (e) { /* private mode */ }
        });

        // Auto-refresh. A full reload rather than a fetch: every page is server-rendered, so reloading is
        // both simpler and exactly as correct, and the countdown makes it obvious the page is not frozen.
        var button = document.getElementById('refresh');
        var tick = document.getElementById('tick');
        var timer = null;
        var left = 0;

        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
            tick.textContent = '';
            button.setAttribute('aria-pressed', 'false');
            try { localStorage.removeItem('firefly-admin-refresh'); } catch (e) { /* private mode */ }
        }

        var INTERVAL = {{ $settings->refreshSeconds }};

        function start() {
            left = INTERVAL;
            tick.textContent = left + 's';
            button.setAttribute('aria-pressed', 'true');
            try { localStorage.setItem('firefly-admin-refresh', '1'); } catch (e) { /* private mode */ }
            timer = setInterval(function () {
                left--;
                tick.textContent = left + 's';
                if (left <= 0) { window.location.reload(); }
            }, 1000);
        }

        button.addEventListener('click', function () { timer ? stop() : start(); });

        try { if (localStorage.getItem('firefly-admin-refresh') === '1') { start(); } } catch (e) { /* private mode */ }

        // Row filtering, shared by every table that opts in with data-filter="<tbody id>".
        document.querySelectorAll('[data-filter]').forEach(function (input) {
            var body = document.getElementById(input.getAttribute('data-filter'));
            var out = input.parentElement.querySelector('[data-filter-count]');
            if (!body) { return; }
            input.addEventListener('input', function () {
                var needle = input.value.toLowerCase();
                var shown = 0;
                Array.prototype.forEach.call(body.rows, function (row) {
                    var hit = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
                    row.hidden = !hit;
                    if (hit) { shown++; }
                });
                if (out) { out.textContent = shown + ' of ' + body.rows.length; }
            });
        });

        // Bean graph: hovering or clicking a node lights its edges and both endpoints, and dims everything
        // else. Reading a dependency diagram is asking "what touches THIS", and a static picture cannot
        // answer that once there is more than a handful of nodes.
        (function () {
            var svg = document.querySelector('.canvas svg');
            if (!svg) { return; }

            var nodes = svg.querySelectorAll('.node');
            var edges = svg.querySelectorAll('.edge');

            function clear() {
                nodes.forEach(function (n) { n.classList.remove('lit', 'dimmed'); });
                edges.forEach(function (e) { e.classList.remove('lit', 'dimmed'); });
            }

            function focus(id) {
                var touched = {};
                touched[id] = true;
                edges.forEach(function (edge) {
                    var from = edge.getAttribute('data-from'), to = edge.getAttribute('data-to');
                    if (from === id || to === id) {
                        edge.classList.add('lit');
                        edge.classList.remove('dimmed');
                        touched[from] = true;
                        touched[to] = true;
                    } else {
                        edge.classList.add('dimmed');
                        edge.classList.remove('lit');
                    }
                });
                nodes.forEach(function (node) {
                    var hit = touched[node.getAttribute('data-id')];
                    node.classList.toggle('lit', !!hit);
                    node.classList.toggle('dimmed', !hit);
                });
            }

            var pinned = null;
            nodes.forEach(function (node) {
                var id = node.getAttribute('data-id');
                node.addEventListener('mouseenter', function () { if (!pinned) { focus(id); } });
                node.addEventListener('mouseleave', function () { if (!pinned) { clear(); } });
                node.addEventListener('click', function () {
                    pinned = pinned === id ? null : id;
                    pinned ? focus(pinned) : clear();
                });
            });
            svg.addEventListener('click', function (event) {
                if (event.target === svg) { pinned = null; clear(); }
            });

            // The graph filter highlights rather than hides: removing a node would silently remove its
            // edges too, and an edge to something you cannot see is worse than no filter at all.
            var find = document.querySelector('[data-filter="graph-body"]');
            if (find) {
                find.addEventListener('input', function () {
                    var needle = find.value.toLowerCase();
                    pinned = null;
                    if (needle === '') { clear(); return; }
                    nodes.forEach(function (node) {
                        var hit = (node.getAttribute('data-search') || '').indexOf(needle) !== -1;
                        node.classList.toggle('lit', hit);
                        node.classList.toggle('dimmed', !hit);
                    });
                    edges.forEach(function (e) { e.classList.add('dimmed'); e.classList.remove('lit'); });
                });
            }
        })();

        // "/" focuses the first filter on the page — the shortcut every log and table UI uses.
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) { return; }
            var tag = (event.target && event.target.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') { return; }
            var first = document.querySelector('[data-filter]');
            if (first) { event.preventDefault(); first.focus(); }
        });
    })();
</script>

{{--
    Page-specific scripts. Without this stack a view's @push('scripts') block is silently DISCARDED — which
    is exactly what happened to the bean graph: its pan/zoom, selection and module filtering were pushed
    here, nothing rendered them, and the page looked static with no error anywhere to say why.
--}}
@stack('scripts')
</body>
</html>
