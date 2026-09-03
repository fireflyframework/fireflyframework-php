{{--
    The dashboard shell. Inline CSS on purpose: a composer package cannot assume npm has run, and a
    dashboard that needs a CDN at request time is useless in exactly the network-isolated environments where
    you most want to look at one. System fonts for the same reason.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · {{ $settings->title }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='9' fill='%23ff9d3c'/%3E%3C/svg%3E">
    <style>
        :root{
            --bg:#fffcf7; --panel:#ffffff; --panel-2:#fffaf2; --rail:#fdf7ee;
            --line:#f0e5d6; --line-2:#e4d6c2;
            --text:#2b2521; --text-2:#6b6259; --text-3:#9a9086;
            --amber:#c95f08; --amber-2:#ffa53d; --amber-soft:#fff2e2;
            --up:#2f7d55; --up-soft:#e9f5ee;
            --down:#b3271c; --down-soft:#fbeae8;
            --off:#8a8076; --off-soft:#f1ede7;
            --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
            --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
        }
        @media (prefers-color-scheme:dark){
            :root{
                --bg:#17130f; --panel:#1f1a15; --panel-2:#251e17; --rail:#1b1611;
                --line:#33291f; --line-2:#413526;
                --text:#f3ede5; --text-2:#b5aa9d; --text-3:#887d70;
                --amber:#ffab52; --amber-2:#ffbe72; --amber-soft:#2d2114;
                --up:#82c99e; --up-soft:#17281f;
                --down:#f0857a; --down-soft:#2c1a17;
                --off:#8d8175; --off-soft:#241f1a;
            }
        }
        *,*::before,*::after{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 var(--sans);-webkit-font-smoothing:antialiased}
        a{color:var(--amber);text-decoration:none}
        a:hover{text-decoration:underline;text-underline-offset:2px}
        :focus-visible{outline:2px solid var(--amber-2);outline-offset:2px;border-radius:4px}

        .shell{display:grid;grid-template-columns:216px minmax(0,1fr);min-height:100vh}
        @media(max-width:820px){.shell{grid-template-columns:1fr}}

        .rail{background:var(--rail);border-right:1px solid var(--line);padding:20px 0 28px}
        @media(max-width:820px){.rail{border-right:0;border-bottom:1px solid var(--line);padding-bottom:12px}}
        .brand{display:flex;align-items:center;gap:9px;padding:0 20px 18px;font-weight:700;letter-spacing:-.01em}
        .dot{width:9px;height:9px;border-radius:50%;background:var(--amber-2);flex:none;
             box-shadow:0 0 0 4px var(--amber-soft)}
        .brand small{display:block;font-weight:400;font-size:11.5px;color:var(--text-3);letter-spacing:0}
        nav{display:flex;flex-direction:column}
        @media(max-width:820px){nav{flex-direction:row;flex-wrap:wrap;gap:2px;padding:0 14px}}
        nav a{
            padding:8px 20px;color:var(--text-2);font-size:14px;border-left:2px solid transparent;
        }
        @media(max-width:820px){nav a{border-left:0;border-radius:6px;padding:6px 10px}}
        nav a:hover{background:var(--panel-2);color:var(--text);text-decoration:none}
        nav a[aria-current]{border-left-color:var(--amber);color:var(--text);font-weight:600;background:var(--panel-2)}

        main{padding:26px clamp(20px,3.5vw,36px) 60px;min-width:0}
        .head{margin-bottom:22px}
        h1{margin:0 0 6px;font-size:22px;letter-spacing:-.02em;font-weight:700}
        .head p{margin:0;color:var(--text-2);font-size:14.5px;max-width:70ch}

        .panel{border:1px solid var(--line);border-radius:12px;background:var(--panel);overflow:hidden}
        .panel + .panel{margin-top:18px}
        .panel > h2{
            margin:0;padding:11px 16px;border-bottom:1px solid var(--line);background:var(--panel-2);
            font-size:11px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--text-3);
            display:flex;justify-content:space-between;gap:12px;align-items:baseline;
        }
        .panel > h2 span{font-family:var(--mono);letter-spacing:.04em;text-transform:none;font-weight:500}

        .tw{overflow-x:auto}
        table{border-collapse:collapse;width:100%;font-size:13.5px}
        th{
            text-align:left;padding:9px 16px;border-bottom:1px solid var(--line);color:var(--text-3);
            font-size:10.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;white-space:nowrap;
        }
        td{padding:9px 16px;border-bottom:1px solid var(--line);vertical-align:top}
        tbody tr:last-child td{border-bottom:0}
        tbody tr:hover{background:var(--panel-2)}
        td.mono,th.mono{font-family:var(--mono);font-size:12.5px}
        td.num{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums}
        .muted{color:var(--text-3)}
        .wrapish{overflow-wrap:anywhere}

        .pill{
            display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
            padding:3px 8px;border-radius:999px;white-space:nowrap;
        }
        .pill.up{background:var(--up-soft);color:var(--up)}
        .pill.down{background:var(--down-soft);color:var(--down)}
        .pill.off{background:var(--off-soft);color:var(--off)}
        .pill.on{background:var(--amber-soft);color:var(--amber)}

        .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0;
               border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--panel)}
        .cards div{padding:14px 16px;border-right:1px solid var(--line)}
        .cards div:last-child{border-right:0}
        .cards dt{font-size:10.5px;letter-spacing:.13em;text-transform:uppercase;color:var(--text-3);margin-bottom:5px}
        .cards dd{margin:0;font-family:var(--mono);font-size:21px;font-weight:600;color:var(--text);
                  font-variant-numeric:tabular-nums;line-height:1.15;min-height:25px;display:flex;align-items:center}

        .filter{
            width:100%;max-width:320px;font:13.5px var(--sans);padding:7px 11px;border-radius:8px;
            border:1px solid var(--line-2);background:var(--panel);color:var(--text);
        }
        .filter::placeholder{color:var(--text-3)}
        .bar{padding:12px 16px;border-bottom:1px solid var(--line);background:var(--panel-2)}

        .empty{padding:22px 16px;color:var(--text-2);font-size:14px}
        code{font-family:var(--mono);font-size:.9em;background:var(--amber-soft);color:var(--amber);
             padding:1px 5px;border-radius:4px}
        select,button.act{
            font:12.5px var(--sans);padding:5px 9px;border-radius:7px;border:1px solid var(--line-2);
            background:var(--panel);color:var(--text);cursor:pointer;
        }
        button.act:hover{border-color:var(--amber);color:var(--amber)}
        .note{margin:14px 0 0;font-size:13px;color:var(--text-3);max-width:74ch}
    </style>
</head>
<body>
<div class="shell">
    <aside class="rail">
        <div class="brand">
            <span class="dot" aria-hidden="true"></span>
            <span>{{ $settings->title }}<small>LaraFly admin</small></span>
        </div>
        <nav>
            @foreach ($nav as $item)
                <a href="{{ $settings->url($item->slug) }}" @if ($item->slug === $active) aria-current="page" @endif>{{ $item->label }}</a>
            @endforeach
        </nav>
    </aside>
    <main>
        @yield('body')
    </main>
</div>
@stack('scripts')
</body>
</html>
