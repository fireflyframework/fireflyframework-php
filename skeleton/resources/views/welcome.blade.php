<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $appName }}</title>
    {{-- Inline so a brand-new application does not 404 on /favicon.ico before you have added your own. --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='9' fill='%23ff9d3c'/%3E%3Ccircle cx='16' cy='16' r='14' fill='none' stroke='%23ff9d3c' stroke-opacity='.28' stroke-width='2.5'/%3E%3C/svg%3E">
    <style>
        /*
         * System fonts only, on purpose: a framework's first page has to render identically on a laptop with
         * no network, inside a container, and behind a corporate proxy.
         *
         * The palette is the firefly's own light — warm amber and gold on warm paper. Every colour is a
         * token, so the dark block below only restates values and never introduces a new one.
         */
        :root {
            --bg:#fffcf7;
            --card:#ffffff;
            --card-2:#fffaf2;
            --line:#f2e8db;
            --line-2:#e6d9c6;

            --text:#2b2521;
            --text-2:#6b6259;
            --text-3:#9a9086;

            --amber:#dc6b0c;
            --amber-2:#ffa53d;
            --gold:#ffc857;
            --amber-soft:#fff3e4;

            --ok:#2f7d55;
            --ok-soft:#e9f5ee;

            --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
            --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;

            --r:16px;
            --r-sm:11px;
            --shadow:0 1px 2px rgba(70,50,25,.05), 0 10px 28px -16px rgba(70,50,25,.28);
        }
        @media (prefers-color-scheme:dark) {
            :root {
                --bg:#17130f;
                --card:#1f1a15;
                --card-2:#251e17;
                --line:#33291f;
                --line-2:#413526;

                --text:#f3ede5;
                --text-2:#b5aa9d;
                --text-3:#887d70;

                --amber:#ffab52;
                --amber-2:#ffbe72;
                --gold:#ffd782;
                --amber-soft:#2d2114;

                --ok:#82c99e;
                --ok-soft:#17281f;

                --shadow:0 1px 2px rgba(0,0,0,.4), 0 12px 32px -18px rgba(0,0,0,.85);
            }
        }

        *,*::before,*::after{box-sizing:border-box}
        html{-webkit-text-size-adjust:100%}
        body{
            margin:0;background:var(--bg);color:var(--text);
            font:16px/1.65 var(--sans);
            -webkit-font-smoothing:antialiased;
        }
        .page{max-width:740px;margin-inline:auto;padding:0 24px 72px}

        a{color:inherit;text-decoration:none}
        :focus-visible{outline:2px solid var(--amber);outline-offset:3px;border-radius:6px}

        /* ── hero ──────────────────────────────────────────────────────────── */
        .hero{text-align:center;padding-block:clamp(52px,10vw,88px) clamp(28px,4vw,40px);position:relative}
        .glow{
            position:absolute;top:4%;left:50%;translate:-50% 0;width:min(540px,88vw);height:300px;
            background:radial-gradient(ellipse at center,rgba(255,165,61,.20),rgba(255,200,87,.07) 45%,transparent 70%);
            pointer-events:none;
        }
        .hero > *:not(.glow){position:relative}

        .lamp{
            width:64px;height:64px;margin:0 auto 24px;border-radius:50%;
            background:radial-gradient(circle at 36% 32%,var(--gold),var(--amber-2) 46%,var(--amber));
            box-shadow:0 0 0 9px var(--amber-soft), 0 12px 34px -10px rgba(220,107,12,.55);
            /* One warm-up on load, then still. A perpetual pulse is a distraction on a page you keep open. */
            animation:light .9s cubic-bezier(.2,.7,.3,1) both;
        }
        @keyframes light{
            from{transform:scale(.82);opacity:0;box-shadow:0 0 0 0 var(--amber-soft), 0 0 0 0 rgba(220,107,12,0)}
            to{transform:none;opacity:1;box-shadow:0 0 0 9px var(--amber-soft), 0 12px 34px -10px rgba(220,107,12,.55)}
        }
        @media (prefers-reduced-motion:reduce){.lamp{animation:none}}

        .status{
            display:inline-flex;align-items:center;gap:7px;margin-bottom:18px;
            background:var(--ok-soft);color:var(--ok);border-radius:999px;padding:5px 13px;
            font-size:12.5px;font-weight:600;
        }
        .status::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}

        h1{
            margin:0 0 12px;font-size:clamp(30px,5.4vw,42px);line-height:1.14;
            letter-spacing:-.028em;font-weight:700;text-wrap:balance;
        }
        .hero p{margin:0 auto;max-width:42ch;font-size:clamp(15.5px,1.7vw,17.5px);color:var(--text-2)}

        /* ── sections ──────────────────────────────────────────────────────── */
        section{margin-top:clamp(36px,5.5vw,48px)}
        h2{
            margin:0 0 13px;font-size:11.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
            color:var(--text-3);
        }

        /* the paths */
        .paths{
            border:1px solid var(--line);border-radius:var(--r);background:var(--card);
            box-shadow:var(--shadow);overflow:hidden;
        }
        .path{
            display:flex;align-items:center;gap:13px;padding:15px 18px;
            border-bottom:1px solid var(--line);transition:background .14s;
        }
        .path:last-child{border-bottom:0}
        a.path:hover{background:var(--card-2)}
        .verb{
            flex:none;font-family:var(--mono);font-size:10.5px;font-weight:700;letter-spacing:.08em;
            color:var(--amber);background:var(--amber-soft);padding:4px 8px;border-radius:6px;
            min-width:48px;text-align:center;
        }
        .url{font-family:var(--mono);font-size:14.5px;flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
        .by{font-size:13px;color:var(--text-3);flex:none;display:none}
        @media(min-width:600px){.by{display:block}}
        .go{flex:none;color:var(--text-3);font-size:16px;line-height:1;transition:color .14s}
        a.path:hover .go{color:var(--amber)}
        .path.plain .url{color:var(--text-2)}
        .empty{margin:0;padding:22px 18px;color:var(--text-2);font-size:14.5px}

        /* next steps */
        .steps{display:grid;gap:10px}
        .step{
            border:1px solid var(--line);border-radius:var(--r-sm);background:var(--card);
            padding:14px 17px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;
        }
        .step .cmd{font-family:var(--mono);font-size:13.5px;flex:1 1 25ch;min-width:0;overflow-wrap:anywhere}
        .step .cmd .dim{color:var(--text-3)}
        .step .note{font-size:13.5px;color:var(--text-3)}
        .copy{
            flex:none;border:1px solid var(--line-2);background:transparent;color:var(--text-3);
            font-family:var(--mono);font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;
            padding:5px 10px;border-radius:6px;cursor:pointer;transition:color .14s,border-color .14s;
        }
        .copy:hover{border-color:var(--amber);color:var(--amber)}
        .copy[data-done]{border-color:var(--ok);color:var(--ok)}

        /* what is running — the live surfaces, with the path shown so it is obvious where they are */
        .tools{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}
        .tools .tool{
            display:flex;flex-direction:column;gap:4px;
            border:1px solid var(--line);border-radius:var(--r-sm);background:var(--card);
            padding:15px 17px;transition:border-color .14s,background .14s;
        }
        .tools .tool:hover{border-color:var(--amber-2);background:var(--card-2)}
        .tools .tool.moved{opacity:.72}
        .tools .tool.moved:hover{border-color:var(--line);background:var(--card)}
        .tools .tool strong{display:flex;align-items:center;gap:6px;font-size:14.5px;font-weight:600}
        .tools .tool .go{color:var(--amber);font-size:13px}
        .tools .tool span{font-size:13px;color:var(--text-3);line-height:1.5}
        .tools .tool code{align-self:flex-start;margin-top:3px;font-size:11.5px}

        /* learn */
        .links{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:10px}
        .links a{
            border:1px solid var(--line);border-radius:var(--r-sm);background:var(--card);
            padding:15px 17px;transition:border-color .14s,background .14s;
        }
        .links a:hover{border-color:var(--amber-2);background:var(--card-2)}
        .links strong{display:block;font-size:14.5px;font-weight:600;margin-bottom:2px}
        .links span{font-size:13px;color:var(--text-3);line-height:1.5}

        /* tip + footer */
        .tip{
            margin-top:clamp(36px,5.5vw,48px);border-radius:var(--r-sm);background:var(--amber-soft);
            padding:15px 18px;font-size:14.5px;color:var(--text-2);
        }
        .tip b{color:var(--text)}

        footer{
            margin-top:clamp(36px,5.5vw,48px);padding-top:22px;border-top:1px solid var(--line);
            display:flex;flex-wrap:wrap;gap:10px 24px;justify-content:space-between;align-items:baseline;
        }
        footer p{margin:0;font-size:13px;color:var(--text-3);max-width:50ch;line-height:1.6}
        .badge{font-family:var(--mono);font-size:11.5px;color:var(--text-3);white-space:nowrap}
        .badge b{color:var(--text-2);font-weight:500}

        code{
            font-family:var(--mono);font-size:.88em;background:var(--amber-soft);color:var(--amber);
            padding:2px 6px;border-radius:5px;
        }
    </style>
</head>
<body>
<div class="page">

    <header class="hero">
        <div class="glow" aria-hidden="true"></div>
        <div class="lamp" aria-hidden="true"></div>
        <span class="status">Running</span>
        <h1>Hello, {{ $appName }}</h1>
        <p>Your application is up. Here is what it serves, and where to go next.</p>
    </header>

    <section>
        <h2>Your routes</h2>
        <div class="paths">
            @forelse ($routes as $route)
                @if (str_contains($route['path'], '{'))
                    <div class="path plain">
                        <span class="verb">{{ $route['method'] }}</span>
                        <span class="url">{{ $route['path'] }}</span>
                        <span class="by">{{ class_basename($route['controller']) }}</span>
                        <span class="go" aria-hidden="true"></span>
                    </div>
                @else
                    <a class="path" href="{{ $route['path'] }}">
                        <span class="verb">{{ $route['method'] }}</span>
                        <span class="url">{{ $route['path'] }}</span>
                        <span class="by">{{ class_basename($route['controller']) }}</span>
                        <span class="go" aria-hidden="true">&rarr;</span>
                    </a>
                @endif
            @empty
                <p class="empty">No routes yet. Run <code>php artisan make:firefly-controller</code> to add one.</p>
            @endforelse

        </div>
    </section>

    <section>
        <h2>Next steps</h2>
        <div class="steps">
            @foreach ([
                ['make:firefly-controller OrderController', 'Add a route'],
                ['make:firefly-service OrderService', 'Add a service'],
                ['firefly:about', 'See what is wired'],
            ] as [$command, $note])
                <div class="step">
                    <span class="cmd"><span class="dim">php artisan</span> {{ $command }}</span>
                    <span class="note">{{ $note }}</span>
                    <button class="copy" type="button" data-copy="php artisan {{ $command }}"
                            aria-label="Copy: php artisan {{ $command }}">Copy</button>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h2>What is running</h2>
        <div class="tools">
            @foreach ($tools as $tool)
                @if ($tool['href'] === null)
                    {{-- Moved to the management port: described, never linked, because the link would 404. --}}
                    <div class="tool moved">
                        <strong>{{ $tool['label'] }}</strong>
                        <span>{{ $tool['blurb'] }}</span>
                        <code>port {{ $managementPort }}</code>
                    </div>
                @else
                    <a class="tool" href="{{ $tool['href'] }}">
                        <strong>{{ $tool['label'] }}<span class="go" aria-hidden="true">&rarr;</span></strong>
                        <span>{{ $tool['blurb'] }}</span>
                        <code>{{ $tool['href'] }}</code>
                    </a>
                @endif
            @endforeach
        </div>

        @if ($managementPort !== null)
            <p class="tip" style="margin-top:12px">
                <b>Management traffic is on port {{ $managementPort }}.</b> The actuator and the dashboard
                answer only there, so they are not reachable from this page. Run
                <code>php artisan firefly:serve --management</code> alongside your application to reach them
                in development.
            </p>
        @endif
    </section>

    <section>
        <h2>Learn</h2>
        <div class="links">
            <a href="https://github.com/fireflyframework/fireflyframework-php#readme">
                <strong>Documentation</strong>
                <span>Modules, configuration and a Laravel cheat-sheet</span>
            </a>
            <a href="https://github.com/fireflyframework/fireflyframework-php/tree/main/book">
                <strong>The book</strong>
                <span>Chapters building one real service, end to end</span>
            </a>
            <a href="https://github.com/fireflyframework/fireflyframework-php/issues">
                <strong>Ask a question</strong>
                <span>Issues and discussions on GitHub</span>
            </a>
        </div>
    </section>

    @if ($bootMode !== 'compiled')
        <p class="tip">
            <b>Before you deploy,</b> run <code>php artisan firefly:cache</code>. Right now your classes are
            scanned at startup, which is what you want while developing.
        </p>
    @endif

    <footer>
        <p>You are seeing this because nothing else claims <code>/</code>. Delete
           <code>app/Http/WelcomeController.php</code> and <code>resources/views/welcome.blade.php</code>
           to remove it.</p>
        <span class="badge">LaraFly <b>{{ $fireflyVersion }}</b> · PHP <b>{{ $phpVersion }}</b> · <b>{{ $environment }}</b></span>
    </footer>

</div>

<script>
    // Copy-to-clipboard. Progressive: with JS off the commands are still selectable text.
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
