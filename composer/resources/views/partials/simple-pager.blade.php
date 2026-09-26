{{-- Previous/next links for a LengthAwarePaginator passed as $pager. --}}
@if($pager->hasPages())
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; font-size: 13px; color: #6b7280;">
        <span>Showing {{ $pager->firstItem() }}–{{ $pager->lastItem() }} of {{ $pager->total() }}</span>
        <span style="display: flex; gap: 12px;">
            @if($pager->previousPageUrl())
                <a href="{{ $pager->previousPageUrl() }}" style="color: #111111; font-weight: 600; text-decoration: none;">&larr; Previous</a>
            @endif
            <span>Page {{ $pager->currentPage() }} of {{ $pager->lastPage() }}</span>
            @if($pager->nextPageUrl())
                <a href="{{ $pager->nextPageUrl() }}" style="color: #111111; font-weight: 600; text-decoration: none;">Next &rarr;</a>
            @endif
        </span>
    </div>
@endif
