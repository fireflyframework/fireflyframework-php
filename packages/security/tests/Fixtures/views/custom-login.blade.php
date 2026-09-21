{{-- An application's own login page. It is handed the same LoginPageModel the framework page gets — the
     action, the field names, the session token and the two query flags — so a branded page posts exactly
     where FormLoginFilter listens without reading configuration or the request on its own. --}}
OUR OWN SIGN-IN · {{ $login->title }} · {{ $login->action }} · {{ $login->usernameParameter }} · {{ $login->passwordParameter }} · {{ $login->csrfToken }}
@if ($login->error) · WRONG @endif
@if ($login->loggedOut) · BYE @endif
@if ($login->rememberMeParameter !== null) · REMEMBER={{ $login->rememberMeParameter }} @endif
<form method="post" action="{{ $login->action }}"><input type="hidden" name="_token" value="{{ $login->csrfToken }}"></form>
