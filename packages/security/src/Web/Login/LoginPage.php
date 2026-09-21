<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

/**
 * The framework's own sign-in page, built as a string — the same reasoning as firefly/web's ErrorPage: no view
 * factory, no filesystem, no network, so it renders in a bare application and in one whose view layer is what
 * is broken. THE DESIGN IS THE FRAMEWORK'S: the palette, type stack and radii are the error page's and the
 * admin dashboard's, the composition the welcome page's — centred, a single column — so a person meets one
 * visual language across the session. No JavaScript: the form is a form, and it works with scripts off.
 *
 * The error state is deliberately the same sentence for a wrong password and an unknown user — the provider
 * makes them indistinguishable on the wire, and the page must not undo that. `autocomplete` values are the
 * ones password managers key on.
 */
final class LoginPage
{
    public static function render(LoginPageModel $login): string
    {
        $title = self::e('Sign in · '.$login->title);

        $notice = '';
        if ($login->error) {
            $notice = '<p class="notice down" role="alert">Those credentials did not work. Check the username and the password, then try again.</p>';
        } elseif ($login->loggedOut) {
            $notice = '<p class="notice idle">You have signed out.</p>';
        }

        $remember = $login->rememberMeParameter === null
            ? ''
            : '<label class="check"><input type="checkbox" name="'.self::e($login->rememberMeParameter).'" value="1"> Keep me signed in</label>';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$title.'</title>'
            .self::favicon()
            .'<style>'.self::css().'</style></head><body>'
            .'<main class="sheet">'
            .'<header class="head"><span class="mark"><span class="dot"></span>'.self::e($login->title).'</span><h1>Sign in</h1></header>'
            .$notice
            .'<form class="panel form" method="post" action="'.self::e($login->action).'" autocomplete="on">'
            .'<input type="hidden" name="_token" value="'.self::e($login->csrfToken).'">'
            .'<label for="ff-username">Username</label>'
            .'<input id="ff-username" name="'.self::e($login->usernameParameter).'" type="text" autocomplete="username" required autofocus>'
            .'<label for="ff-password">Password</label>'
            .'<input id="ff-password" name="'.self::e($login->passwordParameter).'" type="password" autocomplete="current-password" required>'
            .$remember
            .'<button type="submit" class="btn">Sign in</button>'
            .'</form>'
            .'</main></body></html>';
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
  --brand:#e07a17; --brand-ink:#a1520a;
  --down:#c02717; --down-bg:#fbe9e7; --idle:#6b7280; --idle-bg:#f0f0f2;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --r:12px;
}
@media (prefers-color-scheme: dark){
  :root{
    color-scheme:dark;
    --bg:#0f1214; --panel:#15191c; --panel-2:#181d21; --line:#252c32; --line-2:#333c44;
    --ink:#e8ecef; --ink-2:#9aa5af; --ink-3:#6c7883;
    --brand:#ff9d3c; --brand-ink:#ff9d3c;
    --down:#ff8a7a; --down-bg:#2a1614; --idle:#9aa5af; --idle-bg:#1c2226;
  }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 var(--sans);-webkit-font-smoothing:antialiased}
.sheet{max-width:420px;margin:0 auto;padding:72px 20px 72px;display:flex;flex-direction:column;gap:18px;min-width:0}
.head{display:flex;flex-direction:column;gap:6px}
.head h1{margin:0;font-size:28px;letter-spacing:-.02em;line-height:1.15}
.mark{display:inline-flex;align-items:center;gap:9px;font-weight:650;letter-spacing:-.01em;color:var(--ink-2);margin-bottom:10px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--brand);flex:none;box-shadow:0 0 0 3px color-mix(in srgb, var(--brand) 18%, transparent)}
.notice{margin:0;padding:11px 14px;border-radius:var(--r);border:1px solid var(--line);font-size:13.5px;line-height:1.5}
.notice.down{color:var(--down);background:var(--down-bg);border-color:color-mix(in srgb, var(--down) 30%, transparent)}
.notice.idle{color:var(--idle);background:var(--idle-bg)}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);min-width:0}
.form{display:flex;flex-direction:column;gap:8px;padding:22px 20px 20px}
.form label{font-size:11.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-2);margin-top:6px}
.form input[type=text],.form input[type=password]{width:100%;font:inherit;font-size:15px;color:var(--ink);background:var(--panel-2);border:1px solid var(--line-2);border-radius:8px;padding:10px 12px;outline:none}
.form input[type=text]:focus,.form input[type=password]:focus{border-color:var(--brand);box-shadow:0 0 0 3px color-mix(in srgb, var(--brand) 22%, transparent)}
.check{display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:13.5px;color:var(--ink);margin-top:10px}
.check input{width:16px;height:16px;accent-color:var(--brand);margin:0}
.btn{margin-top:14px;font:inherit;font-size:15px;font-weight:650;color:#fff;background:var(--brand);border:0;border-radius:8px;padding:11px 14px;cursor:pointer}
.btn:hover{background:var(--brand-ink)}
.btn:focus-visible{outline:3px solid color-mix(in srgb, var(--brand) 40%, transparent);outline-offset:2px}
@media (max-width:560px){
  .sheet{padding:40px 14px 48px}
}
CSS;
    }
}
