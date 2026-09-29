<ul class="body-tree">
    @foreach ($nodes as $node)
        <li>
            @if ($node->children !== [])
                <details @if ($depth < 2) open @endif>
                    <summary><code>{{ $node->name }}{{ $node->list ? '[]' : '' }}</code> <span class="dim" title="{{ $node->type }}">{{ \Firefly\Admin\Format::leafOf($node->type ?? '') }}</span></summary>
                    @include('firefly-admin::_route-body', ['nodes' => $node->children, 'depth' => $depth + 1])
                </details>
            @else
                <code>{{ $node->name }}{{ $node->list ? '[]' : '' }}</code>
                @if ($node->type !== null)<span class="dim" title="{{ $node->type }}">{{ \Firefly\Admin\Format::leafOf($node->type) }}</span>@endif
                @if ($node->note !== null)<span class="dim"> · {{ $node->note }}</span>@endif
            @endif
        </li>
    @endforeach
</ul>
