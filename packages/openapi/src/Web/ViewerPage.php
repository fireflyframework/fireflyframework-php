<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Web;

/**
 * The API reference UI, as a single self-contained HTML document with NO build step and NO network access.
 *
 * THREE STYLES, selected by `firefly.openapi.viewer.style`:
 *
 *   swagger (default)  The OFFICIAL Swagger UI, served from the application's own origin out of the
 *                      `swagger-api/swagger-ui` composer package. Byte-for-byte the distribution Swagger
 *                      publishes — the full feature set, deep linking, try-it-out, OAuth2 — with no CDN
 *                      request and no npm step, because composer already fetched and pinned the dist.
 *   builtin            A hand-written, dependency-free reference: one <script>, a few hundred bytes of CSS,
 *                      and a single fetch of the spec route. For a deployment that wants a console with no
 *                      third-party JavaScript in it at all, and it is the automatic fallback when the
 *                      swagger-api/swagger-ui package is not installed.
 *   cdn                Swagger UI loaded from jsDelivr. Kept for parity with what most tutorials show, and
 *                      documented as the only style that makes a third-party request at page view.
 *
 * `swagger` and `builtin` both keep the property that matters: an internal API console that phones out to a
 * third-party host on every page view is a supply-chain dependency and a data-protection question, and it
 * renders nothing at all in the air-gapped and locked-down-CSP environments where an internal console is
 * most wanted.
 *
 * The page does the two things a reader actually needs from a generated spec and that raw JSON does not
 * give them: it groups operations by tag with their verbs and paths visible at a glance, and it RESOLVES
 * `$ref` pointers when rendering a body or response schema, so the reader sees the DTO's members instead of
 * a pointer into `#/components/schemas`. Everything else — try-it-out, OAuth flows, code samples — is
 * deliberately absent; that is what the CDN option is for.
 */
final class ViewerPage
{
    /**
     * Pinned by exact version, with Subresource Integrity deliberately NOT claimed: an SRI hash the framework
     * could not verify at release time would be security theatre, and a wrong one would break the page. The
     * honest statement is the one in the README — turning this flag on means the browser fetches code from a
     * third party.
     */
    private const string SWAGGER_UI_VERSION = '5.17.14';

    public function __construct(
        private readonly string $title,
        private readonly SwaggerAssets $assets = new SwaggerAssets,
    ) {}

    /**
     * @param  string  $assetBase  where this package serves the Swagger UI files from, e.g. /openapi/assets
     */
    public function render(string $specUrl, string $style, string $assetBase = ''): string
    {
        return match (true) {
            $style === 'cdn' => $this->swaggerUiFromCdn($specUrl),
            // Falling back rather than rendering a broken page: `swagger` is the DEFAULT, so an application
            // that has not installed swagger-api/swagger-ui would otherwise get a console referencing assets
            // that 404. The built-in reference needs nothing and is always available.
            $style === 'swagger' && $this->assets->available() => $this->swaggerUi($specUrl, $assetBase),
            default => $this->builtIn($specUrl),
        };
    }

    /** The official Swagger UI, wired to assets this package serves from the application's own origin. */
    private function swaggerUi(string $specUrl, string $assetBase): string
    {
        return strtr(<<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>__TITLE__ — API reference</title>
        <link rel="stylesheet" href="__ASSETS__/swagger-ui.css">
        <link rel="icon" type="image/png" href="__ASSETS__/favicon-32x32.png" sizes="32x32">
        <link rel="icon" type="image/png" href="__ASSETS__/favicon-16x16.png" sizes="16x16">
        <style>html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#fafafa}</style>
        </head>
        <body>
        <div id="swagger-ui"></div>
        <script src="__ASSETS__/swagger-ui-bundle.js"></script>
        <script src="__ASSETS__/swagger-ui-standalone-preset.js"></script>
        <script>
        window.onload = function () {
          window.ui = SwaggerUIBundle({
            url: __SPEC_URL__,
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
            plugins: [SwaggerUIBundle.plugins.DownloadUrl],
            layout: 'StandaloneLayout',
            oauth2RedirectUrl: window.location.origin + '__ASSETS__/oauth2-redirect.html',
            tryItOutEnabled: true,
            displayRequestDuration: true,
            filter: true,
            persistAuthorization: true
          });
        };
        </script>
        </body>
        </html>
        HTML, [
            '__TITLE__' => $this->escape($this->title),
            '__SPEC_URL__' => $this->json($specUrl),
            '__ASSETS__' => $this->escape($assetBase),
        ]);
    }

    private function builtIn(string $specUrl): string
    {
        // NOWDOC, not heredoc. The page embeds a JavaScript application, and heredoc interpolation would
        // treat every `$ref`, `$schema` and `$1` in that script as a PHP variable — which is exactly what
        // happened: `$ref` silently became the empty string and $ref resolution stopped working. A nowdoc
        // takes the script verbatim and the two real substitutions are made explicitly below.
        return strtr(<<<'HTML'
        <!DOCTYPE html>
        <html lang="en" data-theme="auto">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>__TITLE__ — API reference</title>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='9' fill='%23ff9d3c'/%3E%3C/svg%3E">
        <style>
        :root{color-scheme:light;
          --bg:#f7f6f3;--shell:#fff;--panel:#fff;--panel-2:#faf9f6;--hover:#f4f2ed;--line:#e7e3db;--line-2:#d6d0c4;
          --ink:#20242a;--ink-2:#5f6672;--ink-3:#8d95a1;
          --brand:#e07a17;--accent:#0f62c9;--accent-soft:#e8f0fc;
          --get:#0f62c9;--get-bg:#e8f0fc;--post:#0f7a44;--post-bg:#e6f4ec;
          --put:#9a6206;--put-bg:#fdf1dd;--del:#c02717;--del-bg:#fbe9e7;
          --req:#c02717;--code-bg:#f4f2ed;
          --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace;
          --sans:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}
        @media (prefers-color-scheme:dark){html[data-theme="auto"]{color-scheme:dark;
          --bg:#0f1214;--shell:#15191c;--panel:#15191c;--panel-2:#191e22;--hover:#1d2328;--line:#252c32;--line-2:#333c44;
          --ink:#e8ecef;--ink-2:#9aa5af;--ink-3:#6c7883;
          --brand:#ff9d3c;--accent:#5da2ff;--accent-soft:#15263c;
          --get:#5da2ff;--get-bg:#15263c;--post:#5cc98c;--post-bg:#12271c;
          --put:#e9b25c;--put-bg:#2a2013;--del:#f2796a;--del-bg:#2b1613;
          --req:#f2796a;--code-bg:#1a1f24;}}
        html[data-theme="dark"]{color-scheme:dark;
          --bg:#0f1214;--shell:#15191c;--panel:#15191c;--panel-2:#191e22;--hover:#1d2328;--line:#252c32;--line-2:#333c44;
          --ink:#e8ecef;--ink-2:#9aa5af;--ink-3:#6c7883;
          --brand:#ff9d3c;--accent:#5da2ff;--accent-soft:#15263c;
          --get:#5da2ff;--get-bg:#15263c;--post:#5cc98c;--post-bg:#12271c;
          --put:#e9b25c;--put-bg:#2a2013;--del:#f2796a;--del-bg:#2b1613;
          --req:#f2796a;--code-bg:#1a1f24;}
        *,*::before,*::after{box-sizing:border-box}
        html,body{height:100%}
        body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 var(--sans);-webkit-font-smoothing:antialiased}
        a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
        :focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}
        button{font:inherit}
        .app{display:grid;grid-template-columns:280px minmax(0,1fr);grid-template-rows:52px minmax(0,1fr);height:100%}
        @media(max-width:880px){.app{grid-template-columns:1fr;grid-template-rows:52px auto minmax(0,1fr)}}
        .top{grid-column:1/-1;display:flex;align-items:center;gap:12px;padding:0 16px;background:var(--shell);border-bottom:1px solid var(--line)}
        .mark{display:flex;align-items:center;gap:9px;font-weight:650;white-space:nowrap}
        .mark i{width:9px;height:9px;border-radius:50%;background:var(--brand);display:block}
        .mark small{color:var(--ink-3);font-weight:400;font-size:11.5px;font-family:var(--mono)}
        .top .sp{flex:1}
        .tool{display:inline-flex;align-items:center;height:28px;padding:0 10px;border-radius:7px;border:1px solid var(--line-2);
              background:transparent;color:var(--ink-2);cursor:pointer;font-size:12px}
        .tool:hover{background:var(--hover);color:var(--ink)}
        aside{background:var(--shell);border-right:1px solid var(--line);overflow-y:auto;padding:12px 0 24px}
        @media(max-width:880px){aside{border-right:0;border-bottom:1px solid var(--line);max-height:230px}}
        .find{margin:0 12px 10px;width:calc(100% - 24px);height:30px;padding:0 10px;border-radius:7px;
              border:1px solid var(--line-2);background:var(--bg);color:var(--ink);font:13px var(--sans)}
        .tag{padding:0 14px;margin:14px 0 4px;font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--ink-3)}
        .op{display:flex;align-items:center;gap:8px;width:100%;padding:5px 14px;background:none;border:0;cursor:pointer;text-align:left;color:var(--ink-2)}
        .op:hover{background:var(--hover);color:var(--ink)}
        .op[aria-current]{background:var(--hover);color:var(--ink);font-weight:600;box-shadow:inset 2px 0 0 var(--accent)}
        .op .p{font-family:var(--mono);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .verb{flex:none;min-width:44px;text-align:center;font-family:var(--mono);font-size:9.5px;font-weight:700;
              letter-spacing:.06em;padding:2px 5px;border-radius:5px;background:var(--get-bg);color:var(--get)}
        .verb.post{background:var(--post-bg);color:var(--post)}
        .verb.put,.verb.patch{background:var(--put-bg);color:var(--put)}
        .verb.delete{background:var(--del-bg);color:var(--del)}
        main{overflow-y:auto;padding:24px clamp(16px,3vw,34px) 64px;min-width:0}
        .wrap{max-width:940px}
        h1{margin:0 0 6px;font-size:20px;font-weight:650;letter-spacing:-.015em}
        .route{display:flex;align-items:center;gap:10px;margin:14px 0 16px;flex-wrap:wrap}
        .route code{font-family:var(--mono);font-size:14.5px;background:var(--code-bg);padding:5px 10px;border-radius:7px;
                    border:1px solid var(--line);overflow-wrap:anywhere}
        .desc{color:var(--ink-2);margin:0 0 18px;max-width:74ch}
        section{border:1px solid var(--line);border-radius:10px;background:var(--panel);overflow:hidden;margin-bottom:16px}
        section > h2{margin:0;padding:9px 14px;border-bottom:1px solid var(--line);background:var(--panel-2);
                     font-size:11px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--ink-3)}
        table{border-collapse:collapse;width:100%;font-size:13px}
        th{text-align:left;padding:7px 14px;background:var(--panel-2);border-bottom:1px solid var(--line);color:var(--ink-3);
           font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
        td{padding:7px 14px;border-bottom:1px solid var(--line);vertical-align:top}
        tr:last-child td{border-bottom:0}
        .mono{font-family:var(--mono);font-size:12.5px}
        .dim{color:var(--ink-3)}
        .req{color:var(--req);font-size:11px;font-weight:700;margin-left:5px}
        .type{font-family:var(--mono);font-size:11.5px;color:var(--ink-2)}
        .rule{display:inline-block;font-family:var(--mono);font-size:10.5px;color:var(--ink-3);
              border:1px solid var(--line);border-radius:5px;padding:0 5px;margin:2px 3px 0 0}
        .tree{padding:10px 14px 12px;font-family:var(--mono);font-size:12.5px}
        .tree ul{list-style:none;margin:0;padding:0 0 0 15px;border-left:1px solid var(--line)}
        .tree > ul{padding-left:0;border-left:0}
        .tree li{padding:2px 0}
        .k{color:var(--ink)}
        .status{display:inline-block;font-family:var(--mono);font-size:11.5px;font-weight:700;padding:2px 7px;border-radius:5px;
                background:var(--post-bg);color:var(--post)}
        .status.c4{background:var(--put-bg);color:var(--put)}
        .status.c5,.status.cd{background:var(--del-bg);color:var(--del)}
        .try{padding:12px 14px;display:flex;flex-direction:column;gap:10px}
        .try .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .try label{font-size:12px;color:var(--ink-2);min-width:110px;font-family:var(--mono)}
        .try input,.try textarea{flex:1 1 220px;min-width:0;font:12.5px var(--mono);padding:6px 9px;border-radius:7px;
              border:1px solid var(--line-2);background:var(--bg);color:var(--ink)}
        .try textarea{min-height:96px;resize:vertical;width:100%}
        .go{height:30px;padding:0 14px;border-radius:7px;border:1px solid var(--accent);background:var(--accent);
            color:#fff;cursor:pointer;font-size:13px;font-weight:600}
        .go:hover{filter:brightness(1.08)}
        .ghost{height:30px;padding:0 12px;border-radius:7px;border:1px solid var(--line-2);background:transparent;
               color:var(--ink-2);cursor:pointer;font-size:12.5px}
        .ghost:hover{border-color:var(--accent);color:var(--accent)}
        pre{margin:0;padding:12px 14px;overflow:auto;max-height:340px;background:var(--code-bg);
            font-family:var(--mono);font-size:12px;line-height:1.6;border-top:1px solid var(--line)}
        .hint{margin:0;padding:10px 14px;color:var(--ink-3);font-size:12.5px}
        .blank{padding:60px 20px;text-align:center;color:var(--ink-2)}
        .blank strong{display:block;font-size:15px;color:var(--ink);margin-bottom:5px}
        [hidden]{display:none!important}
        </style>
        </head>
        <body>
        <div class="app">
          <div class="top">
            <span class="mark"><i></i>__TITLE__<small>API reference</small></span>
            <span class="sp"></span>
            <a class="tool" href=__SPEC_URL__>OpenAPI 3.1</a>
            <button class="tool" id="theme" type="button">Theme</button>
          </div>
          <aside>
            <input class="find" id="find" type="search" placeholder="Filter operations…" aria-label="Filter operations">
            <div id="nav"></div>
          </aside>
          <main><div class="wrap" id="view"><div class="blank"><strong>Loading the document…</strong></div></div></main>
        </div>
        <script>
        (function () {
          var SPEC_URL = __SPEC_URL__;
          var doc = null, ops = [], current = null;
          var view = document.getElementById('view'), nav = document.getElementById('nav');

          var root = document.documentElement;
          try { var t = localStorage.getItem('firefly-api-theme'); if (t) root.setAttribute('data-theme', t); } catch (e) {}
          document.getElementById('theme').addEventListener('click', function () {
            var dark = root.getAttribute('data-theme') === 'dark'
              || (root.getAttribute('data-theme') === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
            var next = dark ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('firefly-api-theme', next); } catch (e) {}
          });

          function esc(v) {
            return String(v === undefined || v === null ? '' : v)
              .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
          }

          // Follows a local JSON pointer. Every schema the generator emits is either inline or a
          // #/components/schemas ref, so a reader never has to hold a pointer in their head.
          function deref(node, seen) {
            seen = seen || 0;
            if (!node || typeof node !== 'object' || seen > 20) { return node; }
            if (typeof node.$ref === 'string' && node.$ref.indexOf('#/') === 0) {
              var at = doc;
              node.$ref.slice(2).split('/').forEach(function (part) {
                at = at && at[part.replace(/~1/g, '/').replace(/~0/g, '~')];
              });
              return deref(at, seen + 1);
            }
            return node;
          }

          function typeOf(schema) {
            if (!schema) { return 'any'; }
            if (schema.type === 'array') { return typeOf(deref(schema.items)) + '[]'; }
            if (Array.isArray(schema.type)) { return schema.type.join(' | '); }
            return schema.type || (schema.properties ? 'object' : 'any');
          }

          // Constraint keywords, shown beside the type — this is where the validation attributes surface.
          var RULES = ['format','pattern','minimum','maximum','exclusiveMinimum','exclusiveMaximum',
                       'minLength','maxLength','minItems','maxItems','enum','default','multipleOf'];

          function rules(schema) {
            if (!schema) { return ''; }
            return RULES.filter(function (k) { return schema[k] !== undefined; }).map(function (k) {
              var v = schema[k];
              return '<span class="rule">' + esc(k) + ' ' + esc(Array.isArray(v) ? v.join(', ') : v) + '</span>';
            }).join('');
          }

          function tree(schema, required, depth) {
            schema = deref(schema);
            depth = depth || 0;
            if (!schema || depth > 12) { return ''; }
            if (schema.type === 'array') {
              return '<ul><li><span class="dim">[ ]</span> ' + esc(typeOf(schema)) + tree(schema.items, [], depth + 1) + '</li></ul>';
            }
            if (!schema.properties) { return ''; }
            var req = schema.required || required || [];
            var out = '<ul>';
            Object.keys(schema.properties).forEach(function (name) {
              var child = deref(schema.properties[name]);
              var isReq = req.indexOf(name) !== -1;
              out += '<li><span class="k">' + esc(name) + '</span>'
                + (isReq ? '<span class="req">required</span>' : '')
                + ' <span class="type">' + esc(typeOf(child)) + '</span> ' + rules(child)
                + (child && child.description ? ' <span class="dim">— ' + esc(child.description) + '</span>' : '')
                + tree(child, [], depth + 1) + '</li>';
            });
            return out + '</ul>';
          }

          function collect() {
            ops = [];
            var paths = doc.paths || {};
            Object.keys(paths).forEach(function (path) {
              Object.keys(paths[path]).forEach(function (verb) {
                var op = paths[path][verb];
                if (!op || typeof op !== 'object') { return; }
                ops.push({
                  id: op.operationId || (verb + ' ' + path),
                  verb: verb.toUpperCase(), path: path, op: op,
                  tag: (op.tags && op.tags[0]) || 'Operations'
                });
              });
            });
          }

          function renderNav(filter) {
            var needle = (filter || '').toLowerCase();
            var byTag = {};
            ops.forEach(function (o) {
              var hay = (o.verb + ' ' + o.path + ' ' + o.id + ' ' + (o.op.summary || '')).toLowerCase();
              if (needle && hay.indexOf(needle) === -1) { return; }
              (byTag[o.tag] = byTag[o.tag] || []).push(o);
            });
            var keys = Object.keys(byTag).sort();
            if (!keys.length) { nav.innerHTML = '<p class="hint">No operation matches.</p>'; return; }
            nav.innerHTML = keys.map(function (tag) {
              return '<div class="tag">' + esc(tag) + '</div>' + byTag[tag].map(function (o) {
                return '<button class="op" data-id="' + esc(o.id) + '"'
                  + (current && current.id === o.id ? ' aria-current="true"' : '') + '>'
                  + '<span class="verb ' + o.verb.toLowerCase() + '">' + o.verb + '</span>'
                  + '<span class="p">' + esc(o.path) + '</span></button>';
              }).join('');
            }).join('');
            nav.querySelectorAll('.op').forEach(function (button) {
              button.addEventListener('click', function () { select(button.getAttribute('data-id'), true); });
            });
          }

          function paramRows(list) {
            return list.map(function (raw) {
              var p = deref(raw);
              var schema = deref(p.schema) || {};
              return '<tr><td class="mono">' + esc(p.name) + (p.required ? '<span class="req">required</span>' : '')
                + '</td><td class="mono dim">' + esc(p.in) + '</td>'
                + '<td><span class="type">' + esc(typeOf(schema)) + '</span> ' + rules(schema) + '</td>'
                + '<td class="dim">' + esc(p.description || '') + '</td></tr>';
            }).join('');
          }

          function bodySchema(op) {
            var content = op.requestBody && deref(op.requestBody).content;
            if (!content) { return null; }
            var type = Object.keys(content)[0];
            return type ? { type: type, schema: deref(content[type].schema) } : null;
          }

          // A minimal example built from the schema, so "Try it" starts from something valid-shaped
          // rather than an empty box the reader has to fill from the table above.
          function sample(schema, depth) {
            schema = deref(schema); depth = depth || 0;
            if (!schema || depth > 8) { return null; }
            if (schema.default !== undefined) { return schema.default; }
            if (schema.enum) { return schema.enum[0]; }
            if (schema.type === 'array') { var one = sample(schema.items, depth + 1); return one === null ? [] : [one]; }
            if (schema.properties) {
              var out = {};
              Object.keys(schema.properties).forEach(function (k) { out[k] = sample(schema.properties[k], depth + 1); });
              return out;
            }
            return { string: '', integer: 0, number: 0, boolean: false }[schema.type] !== undefined
              ? { string: '', integer: 0, number: 0, boolean: false }[schema.type] : null;
          }

          function select(id, push) {
            current = ops.filter(function (o) { return o.id === id; })[0] || null;
            renderNav(document.getElementById('find').value);
            if (!current) { return; }
            if (push) { history.replaceState(null, '', '#' + encodeURIComponent(id)); }

            var op = current.op;
            var params = (op.parameters || []).map(deref);
            var body = bodySchema(op);
            var html = '';

            html += '<h1>' + esc(op.summary || current.id) + '</h1>';
            html += '<div class="route"><span class="verb ' + current.verb.toLowerCase() + '">' + current.verb + '</span>'
                 + '<code>' + esc(current.path) + '</code>'
                 + '<button class="ghost" id="curl">Copy as cURL</button></div>';
            if (op.description) { html += '<p class="desc">' + esc(op.description) + '</p>'; }

            if (params.length) {
              html += '<section><h2>Parameters</h2><table><thead><tr><th>Name</th><th>In</th><th>Type</th><th>Description</th></tr></thead>'
                   + '<tbody>' + paramRows(params) + '</tbody></table></section>';
            }

            if (body) {
              html += '<section><h2>Request body <span class="dim">' + esc(body.type) + '</span></h2>'
                   + '<div class="tree">' + (tree(body.schema, body.schema && body.schema.required) || '<span class="dim">No described members.</span>') + '</div></section>';
            }

            var responses = op.responses || {};
            html += '<section><h2>Responses</h2><table><thead><tr><th>Status</th><th>Content</th><th>Description</th></tr></thead><tbody>';
            Object.keys(responses).forEach(function (code) {
              var r = deref(responses[code]);
              var content = r.content ? Object.keys(r.content)[0] : '';
              var cls = code === 'default' ? 'cd' : (code[0] === '4' ? 'c4' : (code[0] === '5' ? 'c5' : ''));
              html += '<tr><td><span class="status ' + cls + '">' + esc(code) + '</span></td>'
                   + '<td class="mono dim">' + esc(content || '—') + '</td>'
                   + '<td class="dim">' + esc(r.description || '') + '</td></tr>';
            });
            html += '</tbody></table></section>';

            Object.keys(responses).forEach(function (code) {
              var r = deref(responses[code]);
              var content = r.content && r.content[Object.keys(r.content)[0]];
              var schema = content && deref(content.schema);
              var rendered = schema ? tree(schema, schema.required) : '';
              if (rendered) {
                html += '<section><h2>' + esc(code) + ' schema</h2><div class="tree">' + rendered + '</div></section>';
              }
            });

            html += '<section><h2>Try it</h2><div class="try">';
            params.filter(function (p) { return p.in === 'path' || p.in === 'query'; }).forEach(function (p) {
              html += '<div class="row"><label for="p-' + esc(p.name) + '">' + esc(p.name) + ' <span class="dim">' + esc(p.in) + '</span></label>'
                   + '<input id="p-' + esc(p.name) + '" data-param="' + esc(p.name) + '" data-in="' + esc(p.in) + '" placeholder="' + esc(typeOf(deref(p.schema))) + '"></div>';
            });
            if (body) {
              var example = sample(body.schema);
              html += '<textarea id="body" spellcheck="false">' + esc(example === null ? '' : JSON.stringify(example, null, 2)) + '</textarea>';
            }
            html += '<div class="row"><button class="go" id="send">Send request</button>'
                 + '<span class="dim" id="outcome"></span></div></div>'
                 + '<pre id="result" hidden></pre></section>';

            view.innerHTML = html;
            wireTryIt(body);
          }

          function buildUrl() {
            var url = current.path;
            var query = [];
            view.querySelectorAll('[data-param]').forEach(function (input) {
              var name = input.getAttribute('data-param'), value = input.value;
              if (input.getAttribute('data-in') === 'path') {
                url = url.replace('{' + name + '}', encodeURIComponent(value));
              } else if (value !== '') {
                query.push(encodeURIComponent(name) + '=' + encodeURIComponent(value));
              }
            });
            return url + (query.length ? '?' + query.join('&') : '');
          }

          function wireTryIt(body) {
            var curl = document.getElementById('curl');
            if (curl) {
              curl.addEventListener('click', function () {
                var text = "curl -X " + current.verb + " '" + location.origin + buildUrl() + "'"
                  + (body ? " \\\n  -H 'Content-Type: " + body.type + "' \\\n  -d '" + (document.getElementById('body') || {}).value + "'" : '');
                if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text); }
                curl.textContent = 'Copied';
                setTimeout(function () { curl.textContent = 'Copy as cURL'; }, 1400);
              });
            }

            var send = document.getElementById('send');
            if (!send) { return; }
            send.addEventListener('click', function () {
              var out = document.getElementById('result'), outcome = document.getElementById('outcome');
              var init = { method: current.verb, headers: { Accept: 'application/json' } };
              var textarea = document.getElementById('body');
              if (body && textarea && textarea.value.trim() !== '') {
                init.headers['Content-Type'] = body.type;
                init.body = textarea.value;
              }
              outcome.textContent = 'Sending…';
              out.hidden = true;
              var started = performance.now();
              fetch(buildUrl(), init).then(function (response) {
                return response.text().then(function (text) {
                  var ms = Math.round(performance.now() - started);
                  outcome.textContent = response.status + ' ' + response.statusText + ' · ' + ms + ' ms';
                  try { text = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { /* not JSON */ }
                  out.textContent = text === '' ? '(empty body)' : text;
                  out.hidden = false;
                });
              }).catch(function (error) {
                outcome.textContent = 'Request failed';
                out.textContent = String(error);
                out.hidden = false;
              });
            });
          }

          document.getElementById('find').addEventListener('input', function (event) { renderNav(event.target.value); });

          fetch(SPEC_URL, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (json) {
              doc = json;
              collect();
              if (!ops.length) {
                view.innerHTML = '<div class="blank"><strong>No operations documented</strong>'
                  + '<p>The document has no paths. Add a <code>#[RestController]</code>, then regenerate.</p></div>';
                renderNav('');
                return;
              }
              renderNav('');
              var hash = decodeURIComponent((location.hash || '').slice(1));
              select(ops.filter(function (o) { return o.id === hash; }).length ? hash : ops[0].id, false);
            })
            .catch(function (error) {
              view.innerHTML = '<div class="blank"><strong>Could not load the document</strong><p>'
                + esc(String(error)) + '</p></div>';
            });
        })();
        </script>
        </body>
        </html>
        HTML, [
            '__TITLE__' => $this->escape($this->title),
            '__SPEC_URL__' => $this->json($specUrl),
        ]);
    }

    private function swaggerUiFromCdn(string $specUrl): string
    {
        $title = $this->escape($this->title);
        $url = $this->json($specUrl);
        $version = self::SWAGGER_UI_VERSION;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title} — API reference</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@{$version}/swagger-ui.css">
        </head>
        <body>
        <div id="swagger-ui"></div>
        <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@{$version}/swagger-ui-bundle.js" crossorigin></script>
        <script>
        window.onload = function () { window.SwaggerUIBundle({ url: {$url}, dom_id: '#swagger-ui', deepLinking: true }); };
        </script>
        </body>
        </html>
        HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The spec URL reaches JavaScript as a JSON literal, and with HEX_TAG/HEX_AMP/HEX_APOS/HEX_QUOT set so a
     * configured path containing `</script>` (or a quote) cannot break out of the script element. The path
     * comes from application config rather than a request, so this is defence in depth rather than a fix for
     * a known injection — but a viewer that renders a config value into inline script has no business
     * relying on that distinction.
     */
    private function json(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }
}
