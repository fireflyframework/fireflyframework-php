<div class="tw"><table class="ftable">
<caption class="sr-only">{{ $slice->total }} beans · dependents first{{ $explorer->get('q') ? ' matching '.$explorer->get('q') : '' }}</caption>
@include('firefly-admin::_table-head', ['view' => $view, 'query' => $query])
<tbody>@foreach ($slice->rows as $row)<tr><td class="t-qual" title="{{ $row['id'] }}"><a href="{{ $explorer->bean($row['id']) }}"><span class="nm">{{ \Firefly\Admin\Format::leafOf($row['id']) }}</span><span class="ns stem">{{ \Firefly\Admin\Format::stemOf($row['id']) }}</span></a>@if (in_array($row['id'], $exclusive, true))<span> · Only this module</span>@endif @if ($row['in'] === 0 && $explorer->get('module') !== '')<span>unused outside</span>@endif</td><td class="t-token">{{ $row['kind'] }}</td><td class="t-number">{{ $row['in'] }}</td><td class="t-number">{{ $row['out'] }}</td></tr>@endforeach</tbody>
</table></div>
@include('firefly-admin::_pager', ['slice' => $slice, 'query' => $query])
