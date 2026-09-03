{{-- A client-side row filter. Progressive: with JS off the box is inert and every row stays visible. --}}
<div class="bar">
    <input class="filter" type="search" placeholder="{{ $placeholder ?? 'Filter…' }}"
           data-filters="{{ $target }}" aria-label="{{ $placeholder ?? 'Filter rows' }}">
</div>
@once
    @push('scripts')
        <script>
            document.querySelectorAll('.filter').forEach(function (input) {
                input.addEventListener('input', function () {
                    var body = document.getElementById(input.getAttribute('data-filters'));
                    if (!body) { return; }
                    var needle = input.value.toLowerCase();
                    Array.prototype.forEach.call(body.rows, function (row) {
                        row.hidden = needle !== '' && row.textContent.toLowerCase().indexOf(needle) === -1;
                    });
                });
            });
        </script>
    @endpush
@endonce
