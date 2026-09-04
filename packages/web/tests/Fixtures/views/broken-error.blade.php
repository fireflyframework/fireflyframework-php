{{-- Deliberately broken: calls a method the report does not have, which is what an override that has drifted
     from the framework looks like in practice. --}}
{{ $error->noSuchMethodAtAll() }}
