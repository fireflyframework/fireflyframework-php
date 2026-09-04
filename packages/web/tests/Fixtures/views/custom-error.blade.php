{{-- An application's own error page. It is handed the same ErrorReport the built-in page gets, so what it
     may show is decided by the settings and not by this file. --}}
OUR OWN PAGE · {{ $error->status }} · {{ $error->code }}@if ($error->detailed) · {{ $error->message }}@endif
