<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Web;

/**
 * The API reference UI, as a single self-contained HTML document with NO build step and NO network access.
 *
 * WHY NOT SWAGGER UI / REDOC / ELEMENTS. Every off-the-shelf OpenAPI viewer is a bundled JavaScript
 * application, which leaves exactly two ways to ship one: vendor a multi-megabyte minified bundle into a PHP
 * package (bloating every `composer install`, and pinning the framework to a JS release train it cannot
 * audit or patch), or load it from a CDN at request time. The second is worse than it looks: an internal API
 * console that silently phones out to a third-party host on every page view is a supply-chain dependency and
 * a data-protection question, and it simply does not render in the air-gapped and locked-down-CSP
 * environments where an internal API console is most wanted. So the default viewer is hand-written, inline,
 * and dependency-free — a few hundred bytes of CSS and a single fetch of the spec route this same package
 * serves. A Swagger UI page IS available for teams that want the full feature set, behind
 * `firefly.openapi.viewer.cdn`, which defaults to FALSE and is documented as opting into a CDN request.
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

    public function __construct(private readonly string $title) {}

    public function render(string $specUrl, bool $cdn): string
    {
        return $cdn ? $this->swaggerUi($specUrl) : $this->builtIn($specUrl);
    }

    private function builtIn(string $specUrl): string
    {
        $title = $this->escape($this->title);
        $url = $this->json($specUrl);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title} — API reference</title>
        <style>
        :root { color-scheme: light dark; --bg:#fbfbfa; --fg:#1d1d1b; --muted:#6b6b66; --line:#e3e3df; --card:#fff; --code:#f4f4f1; }
        @media (prefers-color-scheme: dark) { :root { --bg:#16161a; --fg:#e9e9e6; --muted:#9a9a95; --line:#2c2c33; --card:#1d1d22; --code:#232329; } }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { max-width: 60rem; margin: 0 auto; padding: 2rem 1.25rem 5rem; }
        h1 { font-size:1.5rem; margin:0 0 .25rem; }
        h2 { font-size:1rem; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin:2.25rem 0 .75rem; }
        .sub { color:var(--muted); margin:0 0 1.5rem; }
        .op { background:var(--card); border:1px solid var(--line); border-radius:.5rem; margin:.5rem 0; overflow:hidden; }
        .op > summary { cursor:pointer; padding:.65rem .85rem; display:flex; gap:.65rem; align-items:center; list-style:none; }
        .op > summary::-webkit-details-marker { display:none; }
        .verb { font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace; letter-spacing:.06em; padding:.35rem .5rem; border-radius:.25rem; color:#fff; min-width:4.2rem; text-align:center; }
        .get{background:#2c6fd1}.post{background:#2e8b57}.put{background:#b8860b}.patch{background:#8a6d3b}.delete{background:#c0392b}.other{background:#6b6b66}
        .path { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; }
        .op-summary { color:var(--muted); margin-left:auto; font-size:12px; }
        .body { padding:0 .85rem .85rem; border-top:1px solid var(--line); }
        table { border-collapse:collapse; width:100%; font-size:13px; margin:.5rem 0 1rem; }
        th,td { text-align:left; padding:.35rem .5rem; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.06em; }
        code { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; background:var(--code); padding:.1rem .3rem; border-radius:.2rem; font-size:12.5px; }
        pre { background:var(--code); padding:.75rem; border-radius:.4rem; overflow-x:auto; font-size:12.5px; margin:.25rem 0 1rem; }
        .req { color:#c0392b; font-weight:600; }
        h3 { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:1rem 0 .25rem; }
        .err { padding:1rem; border:1px solid var(--line); border-radius:.5rem; background:var(--card); }
        a { color:inherit; }
        </style>
        </head>
        <body>
        <div class="wrap" id="root"><p class="sub">Loading the specification…</p></div>
        <script>
        (function () {
          var SPEC_URL = {$url};
          var root = document.getElementById('root');
          var doc = null;

          function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }

          // Resolves a local "#/components/..." pointer against the loaded document. Remote pointers are
          // left alone: this viewer never fetches anything other than the spec route it was given.
          function deref(schema, seen) {
            if (!schema || typeof schema !== 'object' || typeof schema.\$ref !== 'string') return schema;
            var ref = schema.\$ref;
            if (ref.charAt(0) !== '#') return schema;
            seen = seen || [];
            if (seen.indexOf(ref) !== -1) return { type: 'object', description: 'Recursive reference to ' + ref };
            var node = doc;
            var parts = ref.slice(2).split('/');
            for (var i = 0; i < parts.length; i++) {
              node = node && node[parts[i].replace(/~1/g, '/').replace(/~0/g, '~')];
            }
            return node ? deref(node, seen.concat([ref])) : schema;
          }

          function typeOf(schema) {
            if (!schema) return 'any';
            if (schema.\$ref) return schema.\$ref.split('/').pop();
            var t = schema.type;
            if (Array.isArray(t)) return t.join(' | ');
            if (schema.enum) return (t || 'enum') + ' (' + schema.enum.map(String).join(', ') + ')';
            return t || 'any';
          }

          function facets(schema) {
            var keys = ['format','pattern','minimum','maximum','exclusiveMinimum','exclusiveMaximum','minLength','maxLength','minItems','maxItems','multipleOf','const','default'];
            var out = [];
            keys.forEach(function (k) { if (schema && schema[k] !== undefined) out.push(k + ': ' + JSON.stringify(schema[k])); });
            if (schema && schema.allOf) schema.allOf.forEach(function (s) { if (s.pattern) out.push('pattern: ' + JSON.stringify(s.pattern)); });
            if (schema && schema['x-firefly-constraints']) out.push('also: ' + schema['x-firefly-constraints'].join(', '));
            return out.join('; ');
          }

          function schemaTable(schema) {
            var resolved = deref(schema);
            if (!resolved || !resolved.properties) {
              var pre = el('pre'); pre.textContent = JSON.stringify(resolved || {}, null, 2); return pre;
            }
            var required = resolved.required || [];
            var table = el('table');
            var head = el('tr');
            ['Field','Type','Required','Constraints'].forEach(function (h) { head.appendChild(el('th', null, h)); });
            table.appendChild(head);
            Object.keys(resolved.properties).forEach(function (name) {
              var prop = resolved.properties[name];
              var row = el('tr');
              var nameCell = el('td'); nameCell.appendChild(el('code', null, name)); row.appendChild(nameCell);
              row.appendChild(el('td', null, typeOf(prop)));
              row.appendChild(el('td', required.indexOf(name) !== -1 ? 'req' : null, required.indexOf(name) !== -1 ? 'yes' : 'no'));
              row.appendChild(el('td', null, facets(deref(prop))));
              table.appendChild(row);
            });
            return table;
          }

          function operation(path, verb, op) {
            var details = el('details', 'op');
            var summary = el('summary');
            summary.appendChild(el('span', 'verb ' + (['get','post','put','patch','delete'].indexOf(verb) !== -1 ? verb : 'other'), verb.toUpperCase()));
            summary.appendChild(el('span', 'path', path));
            summary.appendChild(el('span', 'op-summary', op.summary || ''));
            details.appendChild(summary);

            var body = el('div', 'body');
            if (op.description) body.appendChild(el('p', 'sub', op.description));

            if (op.parameters && op.parameters.length) {
              body.appendChild(el('h3', null, 'Parameters'));
              var table = el('table');
              var head = el('tr');
              ['Name','In','Required','Type'].forEach(function (h) { head.appendChild(el('th', null, h)); });
              table.appendChild(head);
              op.parameters.forEach(function (p) {
                var row = el('tr');
                var c = el('td'); c.appendChild(el('code', null, p.name)); row.appendChild(c);
                row.appendChild(el('td', null, p.in));
                row.appendChild(el('td', p.required ? 'req' : null, p.required ? 'yes' : 'no'));
                row.appendChild(el('td', null, typeOf(p.schema) + (facets(p.schema) ? ' — ' + facets(p.schema) : '')));
                table.appendChild(row);
              });
              body.appendChild(table);
            }

            if (op.requestBody) {
              Object.keys(op.requestBody.content || {}).forEach(function (media) {
                body.appendChild(el('h3', null, 'Request body — ' + media));
                body.appendChild(schemaTable(op.requestBody.content[media].schema));
              });
            }

            body.appendChild(el('h3', null, 'Responses'));
            var rt = el('table');
            var rh = el('tr');
            ['Status','Description','Body'].forEach(function (h) { rh.appendChild(el('th', null, h)); });
            rt.appendChild(rh);
            Object.keys(op.responses || {}).forEach(function (status) {
              var res = deref(op.responses[status]);
              var row = el('tr');
              var c = el('td'); c.appendChild(el('code', null, status)); row.appendChild(c);
              row.appendChild(el('td', null, res.description || ''));
              var media = Object.keys(res.content || {});
              row.appendChild(el('td', null, media.length ? media.map(function (m) { return m + ': ' + typeOf(res.content[m].schema); }).join(', ') : '—'));
              rt.appendChild(row);
            });
            body.appendChild(rt);

            details.appendChild(body);
            return details;
          }

          function render() {
            root.innerHTML = '';
            root.appendChild(el('h1', null, (doc.info && doc.info.title) || 'API'));
            root.appendChild(el('p', 'sub', 'Version ' + ((doc.info && doc.info.version) || '?') + ((doc.info && doc.info.description) ? ' — ' + doc.info.description : '')));
            var link = el('p', 'sub');
            var a = el('a', null, 'OpenAPI 3.1 document'); a.href = SPEC_URL; link.appendChild(a);
            root.appendChild(link);

            var byTag = {};
            Object.keys(doc.paths || {}).forEach(function (path) {
              Object.keys(doc.paths[path]).forEach(function (verb) {
                var op = doc.paths[path][verb];
                var tag = (op.tags && op.tags[0]) || 'default';
                (byTag[tag] = byTag[tag] || []).push({ path: path, verb: verb, op: op });
              });
            });

            var tags = Object.keys(byTag).sort();
            if (!tags.length) { root.appendChild(el('p', 'err', 'This document declares no operations.')); return; }
            tags.forEach(function (tag) {
              root.appendChild(el('h2', null, tag));
              byTag[tag].forEach(function (entry) { root.appendChild(operation(entry.path, entry.verb, entry.op)); });
            });
          }

          fetch(SPEC_URL, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (json) { doc = json; render(); })
            .catch(function (e) {
              root.innerHTML = '';
              root.appendChild(el('p', 'err', 'Could not load ' + SPEC_URL + ': ' + e.message));
            });
        })();
        </script>
        </body>
        </html>
        HTML;
    }

    private function swaggerUi(string $specUrl): string
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
