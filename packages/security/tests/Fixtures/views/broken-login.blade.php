{{-- Deliberately broken: calls a method the model does not have, which is what an override that has drifted
     from the framework looks like in practice. --}}
{{ $login->noSuchMethodAtAll() }}
