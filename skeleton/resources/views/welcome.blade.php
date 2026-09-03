<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $appName }}</title>
    {{-- Inline so a brand-new application does not 404 on /favicon.ico before you have added your own. --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='6.5' fill='%23e6f58f'/%3E%3Ccircle cx='16' cy='16' r='12' fill='none' stroke='%23e6f58f' stroke-opacity='.3' stroke-width='2'/%3E%3C/svg%3E">
    <style>
        /*
         * ─────────────────────────────────────────────────────────────────────────────────────────────
         *  Two deliberate constraints, both worth knowing before you edit this file.
         *
         *  SYSTEM FONTS ONLY. A framework's first page has to render identically on a laptop with no
         *  network, inside a container, and behind a corporate proxy. So it ships no webfont, and takes
         *  its character from scale, weight and tracking instead — plus heavy use of the monospace
         *  vernacular the subject is actually made of.
         *
         *  ONE THEME, ON PURPOSE. This page commits to a single visual world — bioluminescence in the
         *  dark — rather than hedging across two. Every colour is painted explicitly, so it holds
         *  whatever the host browser prefers. It is a choice, not an omission.
         * ─────────────────────────────────────────────────────────────────────────────────────────────
         */
        :root {
            --void:#070f0e;
            --ground:#0a1614;
            --raise:#0f221e;
            --raise-2:#132b26;
            --line:#1a352f;
            --line-2:#244a42;

            --lume:#e6f58f;          /* the firefly signal — warm yellow-green, never acid */
            --lume-dim:#aac357;
            --aqua:#5ad4c0;          /* the second light: data, links, live values */
            --aqua-dim:#2f8577;

            --text:#e2ece0;
            --text-2:#93a798;
            --text-3:#5f7469;

            --amber:#f2b45c;
            --amber-bg:rgba(242,180,92,.09);
            --good:#9ade7f;
            --good-bg:rgba(154,222,127,.09);

            --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
            --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;

            --gut:clamp(20px,4vw,40px);
        }

        *,*::before,*::after{box-sizing:border-box}
        html{-webkit-text-size-adjust:100%}
        body{
            margin:0;background:var(--ground);color:var(--text);
            font:16px/1.65 var(--sans);
            -webkit-font-smoothing:antialiased;
        }
        .wrap{width:100%;max-width:1080px;margin-inline:auto;padding-inline:var(--gut)}

        a{color:var(--aqua);text-decoration:none}
        a:hover{text-decoration:underline;text-underline-offset:3px;text-decoration-thickness:1px}
        :focus-visible{outline:2px solid var(--lume);outline-offset:3px;border-radius:2px}

        .eyebrow{
            font-family:var(--mono);font-size:11px;font-weight:600;letter-spacing:.2em;
            text-transform:uppercase;color:var(--text-3);margin:0;
        }

        /* ══ hero ═══════════════════════════════════════════════════════════════════════════════ */
        .hero{
            position:relative;isolation:isolate;overflow:hidden;
            background:var(--void);
            padding-block:22px 0;
        }
        /* the glow the whole page is lit by — one light source, upper left, like a firefly at rest */
        .hero::before{
            content:"";position:absolute;z-index:-1;inset:-40% 20% 30% -25%;
            background:radial-gradient(ellipse at 30% 40%,rgba(230,245,143,.13),rgba(90,212,192,.05) 42%,transparent 68%);
            pointer-events:none;
        }

        .topbar{
            display:flex;flex-wrap:wrap;gap:12px 28px;align-items:center;justify-content:space-between;
            padding-bottom:8px;
        }
        .mark{
            display:inline-flex;align-items:center;gap:11px;
            font-family:var(--mono);font-size:12.5px;font-weight:700;letter-spacing:.26em;
            text-transform:uppercase;color:var(--text);
        }
        .bug{
            width:10px;height:10px;border-radius:50%;background:var(--lume);flex:none;
            box-shadow:0 0 0 3px rgba(230,245,143,.12),0 0 20px 3px rgba(230,245,143,.55);
        }
        .env{display:flex;flex-wrap:wrap;gap:4px 20px;font-family:var(--mono);font-size:11.5px;color:var(--text-3)}
        .env b{color:var(--text-2);font-weight:500}

        .headline{padding-block:clamp(38px,7vw,68px) clamp(30px,5vw,46px)}
        h1{
            margin:0 0 20px;
            font-size:clamp(40px,8.4vw,78px);line-height:.94;letter-spacing:-.042em;font-weight:800;
            color:#f4faec;text-wrap:balance;max-width:13ch;
        }
        h1 em{
            font-style:normal;color:var(--lume);
            text-shadow:0 0 44px rgba(230,245,143,.4);
        }
        .sub{margin:0;max-width:54ch;font-size:clamp(16px,1.7vw,18.5px);line-height:1.6;color:var(--text-2)}
        .sub b{color:var(--text);font-weight:600}

        /* the one raised, luminous surface on the page — everything else is rules and space */
        .counts{
            display:grid;grid-template-columns:repeat(5,1fr);
            border:1px solid var(--line-2);border-radius:4px;
            background:linear-gradient(180deg,var(--raise-2),var(--raise));
            box-shadow:0 1px 0 rgba(230,245,143,.07) inset,0 18px 50px -28px rgba(0,0,0,.9);
            overflow:hidden;
        }
        @media(max-width:760px){.counts{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:400px){.counts{grid-template-columns:1fr}}
        .count{padding:17px 20px 16px;border-right:1px solid var(--line);min-width:0}
        .count:last-child{border-right:0}
        @media(max-width:760px){
            .count{border-bottom:1px solid var(--line)}
            .count:nth-child(2n){border-right:0}
        }
        .count dt{
            font-family:var(--mono);font-size:10px;font-weight:600;letter-spacing:.17em;text-transform:uppercase;
            color:var(--text-3);margin-bottom:7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .count dd{
            margin:0;font-family:var(--mono);font-size:27px;font-weight:600;line-height:1;
            color:var(--lume);font-variant-numeric:tabular-nums;letter-spacing:-.02em;
        }
        .count dd small{font-size:14px;color:var(--text-3);font-weight:400;letter-spacing:0}

        /* ══ boot spine — the signature ══════════════════════════════════════════════════════════ */
        .spine{
            background:var(--void);border-top:1px solid var(--line);
            padding-block:clamp(34px,5vw,48px);margin-top:clamp(38px,6vw,60px);
            position:relative;overflow:hidden;
        }
        .spine-head{
            display:flex;flex-wrap:wrap;gap:8px 28px;align-items:baseline;justify-content:space-between;
            margin-bottom:30px;
        }
        .spine-head p{margin:0;font-size:13.5px;color:var(--text-3);max-width:54ch}
        .rail{
            overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;padding-block:2px 8px;
            margin-inline:calc(var(--gut) * -1);padding-inline:var(--gut);
            /* the pipeline is wider than most viewports — fade the edges so it reads as scrollable */
            -webkit-mask-image:linear-gradient(90deg,transparent,#000 var(--gut),#000 calc(100% - var(--gut)),transparent);
            mask-image:linear-gradient(90deg,transparent,#000 var(--gut),#000 calc(100% - var(--gut)),transparent);
        }
        .rail::-webkit-scrollbar{height:5px}
        .rail::-webkit-scrollbar-thumb{background:var(--line-2);border-radius:5px}
        .track{position:relative;display:flex;min-width:max-content;padding-top:26px}
        .track::before{
            content:"";position:absolute;top:4px;left:0;right:0;height:1px;
            background:linear-gradient(90deg,var(--line),var(--line-2) 50%,var(--line));
        }
        /* one firefly signal travelling the pipeline, once, on load */
        .track::after{
            content:"";position:absolute;top:0;left:0;width:130px;height:9px;
            background:radial-gradient(ellipse at center,rgba(230,245,143,.9),rgba(230,245,143,0) 68%);
            animation:travel 3s cubic-bezier(.42,0,.28,1) .4s 1 both;
        }
        @keyframes travel{
            from{transform:translateX(-130px);opacity:0}
            10%{opacity:1}
            85%{opacity:1}
            to{transform:translateX(calc(100% + 130px));opacity:0}
        }
        .step{position:relative;flex:none;width:76px;padding-right:10px}
        .step::before{
            content:"";position:absolute;top:-22px;left:0;width:7px;height:7px;border-radius:50%;
            background:var(--lume-dim);box-shadow:0 0 0 3px rgba(230,245,143,.1);
        }
        .step .ord{
            display:block;font-family:var(--mono);font-size:12px;font-weight:600;color:var(--lume);
            font-variant-numeric:tabular-nums;margin-bottom:5px;letter-spacing:.02em;
        }
        .step .nm{display:block;font-size:11px;line-height:1.35;color:var(--text-3)}
        @media (prefers-reduced-motion:reduce){.track::after{animation:none;opacity:0}}

        /* ══ body ═══════════════════════════════════════════════════════════════════════════════ */
        .band{padding-block:clamp(34px,4.5vw,48px)}
        .band + .band{border-top:1px solid var(--line)}
        .band > .eyebrow{margin-bottom:12px}
        .band > .lede{margin:0 0 26px;max-width:60ch;font-size:16px;color:var(--text-2)}

        /* boot-mode notice — a rule and a colour, not another box */
        .notice{
            display:flex;flex-wrap:wrap;gap:6px 16px;align-items:baseline;
            border-left:2px solid var(--amber);padding:2px 0 2px 18px;
            margin-top:clamp(30px,5vw,44px);
        }
        .notice.is-good{border-left-color:var(--good)}
        .notice strong{font-size:15px;color:var(--amber);flex:none}
        .notice.is-good strong{color:var(--good)}
        .notice span{color:var(--text-2);font-size:15px;flex:1 1 26ch;min-width:0}

        .split{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:clamp(30px,5vw,58px);align-items:start}
        @media(max-width:820px){.split{grid-template-columns:1fr}}
        .split h3,.cmds-head{
            font-family:var(--mono);font-size:10.5px;font-weight:600;letter-spacing:.19em;text-transform:uppercase;
            color:var(--text-3);margin:0 0 14px;
        }

        /* routes — hairline rows, no card */
        .routes{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:13.5px}
        .routes td{padding:12px 0;border-bottom:1px solid var(--line);vertical-align:baseline}
        .routes tr:first-child td{border-top:1px solid var(--line)}
        .routes .verb{width:1%;white-space:nowrap;padding-right:16px;color:var(--lume-dim);font-weight:600;font-size:12px;letter-spacing:.06em}
        .routes .path a{color:var(--text)}
        .routes .path span{color:var(--text)}
        .routes .who{text-align:right;color:var(--text-3);font-size:12.5px;white-space:nowrap;padding-left:16px}
        .none{margin:0;padding:14px 0;border-block:1px solid var(--line);color:var(--text-3);font-size:14px}

        /* actuator — chips carry state in fill, not just colour */
        .chips{display:flex;flex-wrap:wrap;gap:7px;margin:0 0 16px}
        .chip{
            font-family:var(--mono);font-size:11.5px;line-height:1;padding:6px 10px;border-radius:3px;
            border:1px solid var(--line-2);color:var(--text-3);background:transparent;
        }
        .chip.live{
            border-color:transparent;background:rgba(230,245,143,.13);color:var(--lume);font-weight:600;
            box-shadow:0 0 18px -6px rgba(230,245,143,.55);
        }
        .hint{margin:0;font-size:13.5px;color:var(--text-3);line-height:1.6}

        /* commands */
        .cmds{border-top:1px solid var(--line)}
        .cmd{
            display:flex;flex-wrap:wrap;gap:6px 18px;align-items:center;
            padding:13px 0;border-bottom:1px solid var(--line);
        }
        .cmd .line{font-family:var(--mono);font-size:13.5px;color:var(--text);flex:1 1 30ch;min-width:0;overflow-wrap:anywhere}
        .cmd .line .kw{color:var(--text-3)}
        .cmd .why{font-size:13.5px;color:var(--text-3);flex:0 1 auto}
        .copy{
            flex:none;font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;
            border:1px solid var(--line-2);background:transparent;color:var(--text-3);
            padding:5px 10px;border-radius:3px;cursor:pointer;transition:color .12s,border-color .12s;
        }
        .copy:hover{border-color:var(--lume-dim);color:var(--lume)}
        .copy[data-done]{border-color:var(--good);color:var(--good)}

        /* learn */
        .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(224px,1fr));gap:1px;background:var(--line)}
        .cards a{
            display:block;padding:20px;background:var(--ground);
            transition:background .14s;
        }
        .cards a:hover{background:var(--raise);text-decoration:none}
        .cards strong{display:block;font-size:15.5px;font-weight:600;color:var(--text);margin-bottom:5px}
        .cards strong .arw{color:var(--lume);margin-left:3px}
        .cards span{font-size:13.5px;color:var(--text-3);line-height:1.55}

        code{
            font-family:var(--mono);font-size:.87em;color:var(--lume);
            background:rgba(230,245,143,.08);padding:2px 6px;border-radius:3px;
        }

        /* footer */
        footer{border-top:1px solid var(--line);background:var(--void)}
        .foot{
            display:flex;flex-wrap:wrap;gap:26px 48px;justify-content:space-between;
            padding-block:30px 40px;
        }
        .foot .said{max-width:56ch}
        .foot p{margin:0;font-size:13.5px;color:var(--text-3);line-height:1.65}
        .foot p + p{margin-top:10px}
        .foot p strong{color:var(--text-2);font-weight:600}
        .stamp{font-family:var(--mono);font-size:11.5px;color:var(--text-3);line-height:1.9;text-align:right}
        .stamp b{color:var(--text-2);font-weight:500}
        @media(max-width:680px){.stamp{text-align:left}}
    </style>
</head>
<body>

<header class="hero">
    <div class="wrap">
        <div class="topbar">
            <span class="mark"><span class="bug" aria-hidden="true"></span>LaraFly</span>
            <span class="env">
                <span><b>{{ $appName }}</b></span>
                <span>env <b>{{ $environment }}</b></span>
                <span>php <b>{{ $phpVersion }}</b></span>
                <span>laravel <b>{{ $laravelVersion }}</b></span>
            </span>
        </div>

        <div class="headline">
            <h1>Your application is <em>running</em>.</h1>
            <p class="sub">
                You are looking at HTML rendered by a <code>#[Controller]</code>. Every number below was read
                from the application that is serving this page — nothing here is written down.
            </p>
        </div>

        <dl class="counts">
            <div class="count"><dt>Beans</dt><dd>{{ $beanCount }}</dd></div>
            <div class="count"><dt>Conditions met</dt><dd>{{ $conditions['matches'] }}</dd></div>
            <div class="count"><dt>Backed off</dt><dd>{{ $conditions['backedOff'] }}</dd></div>
            <div class="count"><dt>Routes</dt><dd>{{ count($routes) }}</dd></div>
            <div class="count"><dt>Endpoints</dt><dd>{{ count($exposed) }}<small>&thinsp;/&thinsp;{{ count($endpoints) }}</small></dd></div>
        </dl>
    </div>

    <div class="spine">
        <div class="wrap">
            <div class="spine-head">
                <p class="eyebrow">Boot pipeline · {{ count($phases) }} phases</p>
                <p>Every phase ran in this order to produce the page you are reading. The ordinals are gapped
                   so a new phase can slot between two existing ones without renumbering.</p>
            </div>
            <div class="rail" tabindex="0" role="group" aria-label="Boot pipeline phases, in order">
                <div class="track">
                    @foreach ($phases as $phase)
                        <div class="step" title="BootPhase::{{ $phase['name'] }} = {{ $phase['ordinal'] }}">
                            <span class="ord">{{ $phase['ordinal'] }}</span>
                            <span class="nm">{{ $phase['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</header>

<main>
    <div class="wrap">
        <div class="notice {{ $bootMode === 'compiled' ? 'is-good' : '' }}">
            @if ($bootMode === 'compiled')
                <strong>Compiled boot</strong>
                <span>Manifests came from <code>bootstrap/cache/firefly</code>. No reflection ran at startup — this is what production should look like.</span>
            @else
                <strong>Scanned boot</strong>
                <span>Your classes were scanned by reflection at startup, which is what you want while developing. Run <code>php artisan firefly:cache</code> before you deploy.</span>
            @endif
        </div>
    </div>

    <section class="band wrap">
        <p class="eyebrow">What is wired</p>
        <p class="lede">
            Auto-configuration wires a capability only until you supply your own bean, then steps aside —
            {{ $conditions['backedOff'] }} of them did exactly that on this boot.
        </p>

        <div class="split">
            <div>
                <h3>Your routes</h3>
                @if ($routes === [])
                    <p class="none">No routes yet. Create one with <code>php artisan make:firefly-controller</code>.</p>
                @else
                    <table class="routes">
                        <tbody>
                        @foreach ($routes as $route)
                            <tr>
                                <td class="verb">{{ $route['method'] }}</td>
                                <td class="path">
                                    @if (str_contains($route['path'], '{'))
                                        <span>{{ $route['path'] }}</span>
                                    @else
                                        <a href="{{ $route['path'] }}">{{ $route['path'] }}</a>
                                    @endif
                                </td>
                                <td class="who">{{ class_basename($route['controller']) }}::{{ $route['action'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div>
                <h3>Actuator</h3>
                <div class="chips">
                    @foreach ($endpoints as $id)
                        <span class="chip {{ in_array($id, $exposed, true) ? 'live' : '' }}">{{ $id }}</span>
                    @endforeach
                </div>
                <p class="hint">
                    Lit endpoints answer at <a href="{{ $actuatorBase }}">{{ $actuatorBase }}</a>. The rest are
                    registered but withheld — widen <code>exposure.include</code> to publish them.
                </p>
            </div>
        </div>
    </section>

    <section class="band wrap">
        <p class="eyebrow">Where to go next</p>
        <p class="lede">
            The generators scaffold the stereotype and its attributes. The introspection commands answer the
            same questions the actuator does, without starting a server.
        </p>
        <div class="cmds">
            @foreach ([
                ['php artisan ', 'make:firefly-controller OrderController', 'A REST controller'],
                ['php artisan ', 'make:firefly-service OrderService', 'An injectable service bean'],
                ['php artisan ', 'make:firefly-handler PlaceOrder', 'A CQRS command handler'],
                ['php artisan ', 'firefly:about', 'Beans, conditions and mappings'],
                ['php artisan ', 'firefly:cache', 'Compile for a zero-reflection boot'],
            ] as [$prefix, $command, $why])
                <div class="cmd">
                    <span class="line"><span class="kw">{{ $prefix }}</span>{{ $command }}</span>
                    <span class="why">{{ $why }}</span>
                    <button class="copy" type="button" data-copy="{{ $prefix.$command }}"
                            aria-label="Copy {{ $command }}">Copy</button>
                </div>
            @endforeach
        </div>
    </section>

    <section class="band wrap">
        <p class="eyebrow">Learn the framework</p>
        <div class="cards">
            <a href="https://github.com/fireflyframework/fireflyframework-php#readme">
                <strong>Documentation<span class="arw">&rarr;</span></strong>
                <span>Modules, configuration, and a Laravel cheat-sheet</span>
            </a>
            <a href="https://github.com/fireflyframework/fireflyframework-php/tree/main/book">
                <strong>The book<span class="arw">&rarr;</span></strong>
                <span>Thirteen chapters building one real service</span>
            </a>
            <a href="https://github.com/fireflyframework/fireflyframework-php/tree/main/samples/lumen">
                <strong>Sample app<span class="arw">&rarr;</span></strong>
                <span>A wallet and ledger, wired end to end</span>
            </a>
            <a href="https://github.com/fireflyframework/fireflyframework-php/issues">
                <strong>Ask a question<span class="arw">&rarr;</span></strong>
                <span>Issues and discussions on GitHub</span>
            </a>
        </div>
    </section>
</main>

<footer>
    <div class="wrap foot">
        <div class="said">
            <p><strong>This page is here because nothing else claims <code>/</code>.</strong>
               Delete <code>app/Http/WelcomeController.php</code> and
               <code>resources/views/welcome.blade.php</code> and it is gone — nothing else refers to either.</p>
            <p>LaraFly is the PHP member of the Firefly Framework family: Spring Boot's cohesion, native to
               Laravel. Released under the Apache-2.0 licence.</p>
        </div>
        <div class="stamp">
            <div>LaraFly <b>{{ $fireflyVersion }}</b></div>
            <div>Laravel <b>{{ $laravelVersion }}</b> · PHP <b>{{ $phpVersion }}</b></div>
            <div><b>{{ $environment }}</b>{{ $debug ? ' · debug' : '' }} · <b>{{ $bootMode }}</b></div>
        </div>
    </div>
</footer>

<script>
    // Copy-to-clipboard on the command list. Progressive: with JS off the commands are still selectable
    // text and the button is simply inert.
    document.querySelectorAll('.copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var text = button.getAttribute('data-copy') || '';

            var confirm = function () {
                var label = button.textContent;
                button.textContent = 'Copied';
                button.setAttribute('data-done', '');
                setTimeout(function () {
                    button.textContent = label;
                    button.removeAttribute('data-done');
                }, 1400);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(confirm, function () {});
                return;
            }

            // http://localhost is not a secure context in every browser, so fall back to a hidden textarea.
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); confirm(); } catch (e) { /* clipboard unavailable */ }
            document.body.removeChild(area);
        });
    });
</script>
</body>
</html>
