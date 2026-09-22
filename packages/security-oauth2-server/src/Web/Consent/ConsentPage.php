<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Consent;

/**
 * The framework's consent page (Spring's default consent page), built as a string like the login page and for
 * the same reasons: no view factory, no filesystem, no JavaScript, the palette and composition of the error and
 * login pages so a person meets one visual language across the sign-in. Every requested scope is a checked box
 * the person may clear; the two buttons post `action=approve` or `action=deny`; the session token and the
 * pending state ride as hidden fields.
 */
final class ConsentPage
{
    public static function render(ConsentPageModel $consent): string
    {
        $rows = '';
        foreach ($consent->scopes as $scope) {
            $rows .= '<label class="scope"><input type="checkbox" name="scope[]" value="'.self::e($scope['scope']).'" checked>'
                .'<span><strong>'.self::e($scope['scope']).'</strong> '.self::e($scope['description'])
                .($scope['approved'] ? ' <em>(already allowed)</em>' : '').'</span></label>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.self::e('Allow access · '.$consent->title).'</title>'
            .'<style>'.self::css().'</style></head><body>'
            .'<main class="sheet">'
            .'<header class="head"><span class="mark"><span class="dot"></span>'.self::e($consent->title).'</span>'
            .'<h1>Allow access?</h1>'
            .'<p class="lead"><strong>'.self::e($consent->clientName).'</strong> wants to access your account (<code>'.self::e($consent->principalName).'</code>).</p></header>'
            .'<form class="panel form" method="post" action="'.self::e($consent->action).'">'
            .'<input type="hidden" name="_token" value="'.self::e($consent->csrfToken).'">'
            .'<input type="hidden" name="state" value="'.self::e($consent->state).'">'
            .'<p class="hint">It asks for:</p>'
            .$rows
            .'<div class="actions"><button type="submit" class="btn" name="action" value="approve">Allow</button>'
            .'<button type="submit" class="btn ghost" name="action" value="deny">Deny</button></div>'
            .'</form>'
            .'</main></body></html>';
    }

    /** The sentence next to a scope: the three OIDC ones by name, anything else as "Access <scope>". */
    public static function describe(string $scope): string
    {
        return match ($scope) {
            'openid' => 'Sign you in and know who you are',
            'profile' => 'Read your profile',
            'email' => 'Read your email address',
            default => 'Access '.$scope,
        };
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
  --brand:#e07a17; --brand-ink:#a1520a;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --r:12px;
}
@media (prefers-color-scheme: dark){
  :root{
    color-scheme:dark;
    --bg:#0f1214; --panel:#15191c; --panel-2:#181d21; --line:#252c32; --line-2:#333c44;
    --ink:#e8ecef; --ink-2:#9aa5af; --ink-3:#6c7883;
    --brand:#ff9d3c; --brand-ink:#ff9d3c;
  }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 var(--sans);-webkit-font-smoothing:antialiased}
.sheet{max-width:460px;margin:0 auto;padding:72px 20px 72px;display:flex;flex-direction:column;gap:18px;min-width:0}
.head{display:flex;flex-direction:column;gap:6px}
.head h1{margin:0;font-size:28px;letter-spacing:-.02em;line-height:1.15}
.lead{margin:4px 0 0;color:var(--ink-2)}
.mark{display:inline-flex;align-items:center;gap:9px;font-weight:650;letter-spacing:-.01em;color:var(--ink-2);margin-bottom:10px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--brand);flex:none;box-shadow:0 0 0 3px color-mix(in srgb, var(--brand) 18%, transparent)}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);min-width:0}
.form{display:flex;flex-direction:column;gap:10px;padding:22px 20px 20px}
.hint{margin:0 0 4px;font-size:11.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-2)}
.scope{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel-2)}
.scope input{margin-top:3px;accent-color:var(--brand)}
.scope em{color:var(--ink-3);font-style:normal}
.actions{display:flex;gap:10px;margin-top:10px}
.btn{font:inherit;font-size:15px;font-weight:650;color:#fff;background:var(--brand);border:0;border-radius:8px;padding:11px 14px;cursor:pointer;flex:1}
.btn:hover{background:var(--brand-ink)}
.btn.ghost{color:var(--ink);background:transparent;border:1px solid var(--line-2)}
.btn.ghost:hover{background:var(--panel-2)}
.btn:focus-visible{outline:3px solid color-mix(in srgb, var(--brand) 40%, transparent);outline-offset:2px}
@media (max-width:560px){ .sheet{padding:40px 14px 48px} .actions{flex-direction:column} }
CSS;
    }
}
