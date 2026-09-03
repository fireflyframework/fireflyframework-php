@extends('firefly-admin::layout')
@section('title', 'Health')
@section('topchips')
    <span class="chip {{ $aggregate === 'UP' ? 'up' : ($aggregate === 'DOWN' ? 'down' : '') }}">{{ $aggregate }}</span>
@endsection
@section('body')
    @php use Firefly\Admin\Format; @endphp

    <div class="head">
        <h1>Health</h1>
        <p>Every indicator this process registered, called directly so its details are visible here even
           when the HTTP endpoint withholds them.</p>
    </div>

    <div class="panel">
        @include('firefly-admin::_panel-head', [
            'title' => 'Indicators', 'count' => count($indicators),
            'filter' => 'health-body', 'placeholder' => 'Filter indicators…',
        ])
        @if ($indicators === [])
            @include('firefly-admin::_empty', [
                'title' => 'No indicators registered',
                'body' => 'Implement <code>Firefly\Actuator\Health\HealthIndicator</code> and register it as a bean. The framework ships a ping indicator, a disk-space indicator, and an opt-in database indicator.',
            ])
        @else
            <div class="tw">
                <table>
                    <thead><tr><th>Indicator</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody id="health-body">
                    @foreach ($indicators as $indicator)
                        <tr>
                            <td class="mono tight">{{ $indicator['name'] }}</td>
                            <td class="tight"><span class="chip {{ $indicator['status'] === 'UP' ? 'up' : 'down' }}">{{ $indicator['status'] }}</span></td>
                            <td class="mono dim wrap">
                                @forelse ($indicator['details'] as $key => $value)
                                    <div><span class="dim">{{ $key }}</span> {{ Format::detail((string) $key, $value) }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="note">The aggregate is the worst status any indicator reports. The HTTP endpoint answers 503
    when it is DOWN, which is what a load balancer reads.</p>
@endsection
