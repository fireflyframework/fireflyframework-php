@extends('firefly-admin::layout')
@section('title', 'Conditions')
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Conditions</h1>
        <p>Auto-configuration wires a capability only until you supply your own bean, then steps aside.
           Everything under <em>Backed off</em> is a decision the framework made in your favour.</p>
    </div>

    <div class="grid two">
        @foreach ([['Applied', $applied, $appliedQuery], ['Backed off', $backed, $backedQuery]] as [$title, $slice, $panelQuery])
            <div class="panel">
                @include('firefly-admin::_panel-head', [
                    'title' => $title, 'count' => $slice->total, 'query' => $panelQuery,
                    'placeholder' => 'Search by class or condition…',
                ])
                @if ($slice->isEmpty())
                    @include('firefly-admin::_empty', $panelQuery->isFiltered()
                        ? ['title' => 'Nothing matches', 'body' => 'No class or condition here contains that. <a href="'.e($panelQuery->link(['q' => null, 'page' => null])).'">Show them all</a>.']
                        : ['title' => 'Nothing here', 'body' => $title === 'Applied'
                            ? 'No condition matched — unusual, and worth checking that auto-configuration is discovering your packages.'
                            : 'No auto-configuration found a reason to stand down. Every capability is running its framework default.'])
                @else
                    <div class="tw">
                        <table class="ftable">
                            @include('firefly-admin::_table-head', ['view' => $view, 'query' => $panelQuery])
                            <tbody>
                            @foreach ($slice->rows as $row)
                                <tr>
                                    <td class="t-qual" title="{{ $row['class'] }}">
                                        <span class="nm">{{ Format::leafOf($row['class']) }}</span>
                                        <span class="ns stem">{{ Format::stemOf($row['class']) }}</span>
                                    </td>
                                    <td class="t-token dim" title="{{ $row['condition'] }}">#[{{ Format::leafOf($row['condition']) }}]</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @include('firefly-admin::_pager', ['slice' => $slice, 'query' => $panelQuery])
                @endif
            </div>
        @endforeach
    </div>
@endsection
