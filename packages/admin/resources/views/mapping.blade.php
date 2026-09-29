@extends('firefly-admin::layout')
@section('title', $detail->route->httpMethod.' '.$detail->route->path)
@section('body')
    @php
        use Firefly\Admin\Format;
        use Firefly\Admin\Route\RouteBodyNode;
        use Firefly\Admin\Route\RouteInspector;
        $route = $detail->route;
        $back = $query->link();
    @endphp
    <div class="head route-head">
        <a class="route-back" href="{{ $back }}">← Back to routes</a>
        <h1 id="route-detail" tabindex="-1"><span class="verb">{{ $route->httpMethod }}</span> <span class="mono">{{ $route->path }}</span></h1>
        <p class="wrap"><code>{{ $route->controllerClass }}::{{ $route->methodName }}()</code> · {{ $route->name ?? 'Unnamed route' }}</p>
        <nav class="route-links" aria-label="Related inspection pages">
            @foreach ($links as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach
        </nav>
        @if ($detail->siblings !== [])
            <details class="route-siblings" open>
                <summary>Same controller · {{ count($detail->siblings) }} other registrations</summary>
                <ul>
                    @foreach ($detail->siblings as $sibling)
                        <li><a href="{{ $back.(str_contains($back, '?') ? '&' : '?').'route='.rawurlencode(RouteInspector::keyOf($sibling)) }}#route-detail"><span class="verb">{{ $sibling->httpMethod }}</span> <code>{{ $sibling->path }}</code></a>
                            <span class="dim">{{ $sibling->methodName }}() · status {{ $sibling->status }}{{ $sibling->status !== $route->status ? ' (different)' : '' }} · {{ count($sibling->bindings) }} arguments{{ $sibling->bindings !== $route->bindings ? ' (different contract)' : ' (same contract)' }}</span>
                            <span class="dim"> · compiled: {{ in_array('body', array_column($sibling->bindings, 'kind'), true) ? 'body' : 'no body' }} · {{ in_array(true, array_column($sibling->bindings, 'valid'), true) ? 'validates' : 'no validation' }} · {{ array_column($sibling->bindings, 'pattern') !== [] ? 'path pattern' : 'no path pattern' }}</span></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
    <div class="stats">
        <dl class="stat"><dt>Default success status</dt><dd>{{ $route->status }}</dd></dl>
        <dl class="stat"><dt>HTML stereotype</dt><dd>{{ $route->html ? 'Declared' : 'No / legacy' }}</dd></dl>
        <dl class="stat"><dt>Caller arguments</dt><dd>{{ count($detail->caller) }}</dd></dl>
        <dl class="stat"><dt>Injected arguments</dt><dd>{{ count($detail->injected) }}</dd></dl>
        <dl class="stat"><dt>Route source</dt><dd>{{ $bootMode }}</dd></dl>
    </div>
    @if ($manifestTime !== false)<p class="dim">Route artifact modified {{ Format::since($manifestTime, $now) }}. Rebuild with <code>php artisan firefly:cache</code> after changing declarations.</p>@endif
    @if (count($detail->registrations) > 1)
        <section class="panel route-panel" aria-labelledby="route-duplicates">
            <h2 id="route-duplicates">Duplicate registrations</h2>
            <p>The last registration wins for this verb and path. Earlier handlers are shadowed.</p>
            <ol>@foreach ($detail->registrations as $registration)<li class="wrap"><strong>{{ $loop->last ? 'Effective' : 'Shadowed' }}</strong> · <code>{{ $registration->controllerClass }}::{{ $registration->methodName }}()</code></li>@endforeach</ol>
        </section>
    @endif
    <section class="panel" aria-labelledby="route-request">
        <div class="route-panel"><h2 id="route-request">Request · what the caller sends</h2><p class="dim">Signature positions are preserved. Defaults are compiled attribute values, not necessarily PHP signature defaults. Null does not establish nullability; an unrecorded type may be union, intersection or untyped.</p></div>
        @if ($detail->caller === [])<p class="route-panel">No caller-supplied arguments in the compiled contract.</p>
        @else
            <div class="tw" tabindex="0" role="region" aria-label="Request bindings, scroll horizontally for all columns">
                <table class="ftable route-bindings">
                    @include('firefly-admin::_table-head', ['view' => $bindingView, 'query' => null])
                    <tbody>@foreach ($detail->caller as $binding)
                        <tr>
                            <td class="t-number">{{ $binding->position }}</td>
                            <td class="t-token" title="${{ $binding->plan['name'] }}">${{ $binding->plan['name'] }}</td>
                            <td class="t-pill">{{ $binding->plan['kind'] }}</td>
                            <td class="t-token" title="{{ $binding->plan['key'] }}">{{ $binding->plan['key'] }}</td>
                            <td class="t-qual" title="{{ $binding->plan['type'] }}"><span class="nm">{{ Format::leafOf($binding->plan['type'] ?? 'not recorded') }}</span><span class="ns stem">{{ Format::stemOf($binding->plan['type'] ?? '') }}</span></td>
                            <td class="t-pill"><span class="bool {{ $binding->plan['required'] ? 'yes' : 'no' }}">{{ $binding->plan['required'] ? 'yes' : 'no' }}</span></td>
                            <td class="t-token" title="{{ $binding->defaultLabel() }}">{{ $binding->defaultLabel() }}</td>
                            <td class="t-pill"><span class="bool {{ $binding->plan['valid'] ? 'yes' : 'no' }}">{{ $binding->plan['valid'] ? 'yes' : 'no' }}</span></td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
            @foreach ($detail->caller as $binding)
                @if ($binding->notFoundMessage !== null)
                    <p class="route-panel wrap"><strong>${{ $binding->plan['name'] }} pattern</strong> <code>^(?:{{ $binding->plan['pattern'] }})$</code> (case-insensitive). A mismatch returns 404 <code>{{ $binding->plan['notFoundCode'] ?? 'RESOURCE_NOT_FOUND' }}</code>: {{ $binding->notFoundMessage }}</p>
                @endif
            @endforeach
        @endif
    </section>
    <section class="panel route-panel" aria-labelledby="route-failures">
        <h2 id="route-failures">Before the controller · possible binding failures</h2>
        <p class="dim">Framework defaults derived from this contract. Exception handlers may replace the response or status. Resolver-specific failures are not inferred.</p>
        @if ($detail->failures === [])<p>No default binding failures are implied by these arguments.</p>
        @else<ul class="route-failures">@foreach ($detail->failures as $failure)
            <li><span class="code">{{ $failure['status'] }}</span> <code>{{ $failure['code'] }}</code> · <strong>${{ $failure['binding'] }}</strong><span>{{ $failure['reason'] }}</span></li>
        @endforeach</ul>@endif
    </section>
    {{-- Services are collaborators, never request parameters. A resolver claim overrides the compiled kind. --}}
    <section class="panel route-panel" aria-labelledby="route-injected">
        <h2 id="route-injected">Handler arguments · what the container supplies</h2>
        @if ($detail->injected === [])<p>No injected arguments in this signature.</p>
        @else<ol class="route-injected">@foreach ($detail->injected as $binding)
            <li value="{{ $binding->position }}" class="wrap"><code>${{ $binding->plan['name'] }}</code> · <code>{{ $binding->plan['type'] ?? 'not recorded (union, intersection or untyped)' }}</code>
                @if ($binding->resolver !== null)<span class="code">Resolver claimed</span><p>Supplied by <code>{{ $binding->resolver }}</code>; compiled kind <code>{{ $binding->plan['kind'] }}</code> is overridden.</p>
                @else<span class="code">Container service</span>@endif
                @if (array_key_exists('nullable', $binding->plan))<span class="dim"> · {{ $binding->plan['nullable'] ? 'may be null' : 'non-null' }}</span>@endif
            </li>
        @endforeach</ol>@endif
    </section>
    @foreach ($detail->caller as $binding)
        @if ($binding->plan['kind'] === 'body')
            <section class="panel route-panel" aria-labelledby="route-body-{{ $binding->position }}">
                <h2 id="route-body-{{ $binding->position }}">Request body · ${{ $binding->plan['name'] }}</h2>
                <p>{{ $binding->plan['valid'] && $binding->plan['type'] !== null ? 'Validated before hydration; validation can answer 422.' : 'No body validation is declared.' }} Validation rules are not part of the route manifest.</p>
                @php $bodyNodes = RouteBodyNode::forBinding($binding); @endphp
                @if ($bodyNodes !== [])@include('firefly-admin::_route-body', ['nodes' => $bodyNodes, 'depth' => 0])
                @else<p class="dim">{{ $binding->plan['type'] === null || ! class_exists($binding->plan['type']) ? 'The decoded array is passed through.' : 'No constructor properties are recorded in this manifest.' }}</p>@endif
            </section>
        @endif
    @endforeach
    <section class="panel route-panel" aria-labelledby="route-response">
        <h2 id="route-response">Response</h2>
        <p>Default success status <strong>{{ $route->status }}</strong>. A returned response can override it.</p>
        <p>{{ $route->html ? 'The manifest records the #[Controller] HTML stereotype. This is declaration metadata, not a media-type guarantee.' : 'No HTML stereotype is recorded. Older manifests may omit this metadata.' }}</p>
        <p>The returned value and Accept determine the response. Views and HTML-capable values can render as HTML; data is negotiated; returned responses retain their own headers.</p>
        @if ($route->html)<p class="dim">HTML controllers are excluded from the OpenAPI document unless <code>firefly.openapi.include-html</code> is enabled.</p>@endif
        <h3>Exception handlers</h3>
        <p class="dim">Controller-local handlers take precedence over global handlers; within that scope, the most-derived matching exception class wins.</p>
        @if ($handlers === [])<p>No matching local or global exception handlers are registered.</p>
        @else<ul>@foreach ($handlers as $handler)<li class="wrap">{{ $handler->global ? 'Global' : 'Controller-local' }} · <code>{{ $handler->exceptionClass }}</code> → <code>{{ $handler->handlerClass }}::{{ $handler->methodName }}()</code></li>@endforeach</ul>@endif
    </section>
    @if ($settings->routeAdvice)
        <details class="panel route-panel"><summary>Advice · compiled contract and runtime bindings</summary>
            <p class="dim">Source: {{ $adviceSource }}. Listed outermost first. LIVE means the interceptor is bound; it does not prove a request executed it. UNBOUND advice can fail proxy creation unless explicitly permitted to be INERT.</p>
            @if ($advice === [])<p>No method advice is recorded in the available plan.</p>
            @else<ol>@foreach ($advice as $item)<li class="wrap"><strong>{{ $item['id'] }}</strong> · order {{ $item['order'] }} · <span class="code">{{ $item['state'] }}</span><p><code>{{ $item['interceptor'] }}</code></p><details><summary>Declared settings and meter names</summary><pre>{{ $item['contract'] }}</pre></details></li>@endforeach</ol>@endif
            <p class="dim">Meter names are declarations; metric totals belong to the application, not this route.</p>
        </details>
    @endif
    @if ($metadata !== null)
        <details class="panel route-panel"><summary>As Laravel registered it</summary>
            <p class="wrap">URI <code>{{ $metadata['uri'] }}</code> · name <code>{{ $metadata['name'] ?? 'unnamed' }}</code> · domain {{ $metadata['domain'] ?? 'any host' }}</p>
            <p class="wrap">Declared route middleware: {{ implode(', ', $metadata['middleware']) ?: 'none' }}. Global middleware is not included.</p>
            @foreach ($metadata['patterns'] as $name => $pattern)<p class="wrap"><code>{{ $name }}</code> constraint: <code>{{ $pattern }}</code></p>@endforeach
        </details>
    @endif
    <details class="panel route-panel"><summary>Dispatch order and inspection limits</summary>
        <ol><li>Resolve the controller.</li><li>Bind arguments in signature order:
            <ol>@foreach ($detail->arguments() as $binding)<li class="wrap"><code>${{ $binding->plan['name'] }}</code> · {{ $binding->resolver !== null ? 'registered resolver' : ($binding->plan['kind'] === 'service' ? 'container service' : $binding->plan['kind'].' binding') }}</li>@endforeach</ol>
            <a href="#route-failures">See binding failures above</a>.</li><li>Check controller authorization after arguments are available.</li><li>Invoke the handler and apply after-invocation checks.</li><li>Build the response with default status {{ $route->status }}.</li></ol>
        {{-- Proxy security absence is not an authorization verdict; URL rules alone cannot establish public access. --}}
        <p>Security authorization is not inferred from this page. Controller and method checks, URL rules and the concrete request may all affect access.</p>
        <p>No handler, argument resolver or synthetic request is executed by this inspection. HTTP traffic is a separate application-wide view.</p>
    </details>
@endsection
@push('scripts')
<script>document.getElementById('route-detail')?.focus({preventScroll:true});</script>
@endpush
