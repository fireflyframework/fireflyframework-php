{{-- An application's own consent page: handed the same ConsentPageModel the framework page gets. --}}
OUR OWN CONSENT · {{ $consent->title }} · {{ $consent->clientName }} · {{ $consent->principalName }}
<form method="post" action="{{ $consent->action }}">
<input type="hidden" name="_token" value="{{ $consent->csrfToken }}">
<input type="hidden" name="state" value="{{ $consent->state }}">
@foreach ($consent->scopes as $scope)
<input type="checkbox" name="scope[]" value="{{ $scope['scope'] }}" checked> {{ $scope['description'] }}
@endforeach
<button name="action" value="approve">Allow</button>
</form>
