{{-- Messages badge with unread count --}}
<a href="{{ route('messages.index') }}" class="ag-messages-badge" title="Messages" id="agMessagesBadge">
    <i class="fas fa-envelope"></i>
    <span class="ag-badge" id="agMessagesCount" style="display: none;">0</span>
</a>

<style>
.ag-messages-badge {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 6px;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.ag-messages-badge:hover {
    background-color: #f3f4f6;
    color: #1f2937;
}

.ag-messages-badge .ag-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background-color: #ef4444;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    min-width: 20px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const badge = document.getElementById('agMessagesBadge');
    const countBadge = document.getElementById('agMessagesCount');

    // Fetch unread count
    fetch('/api/v1/temporary-messages', {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.count > 0) {
            countBadge.textContent = data.count > 99 ? '99+' : data.count;
            countBadge.style.display = 'flex';
        }
    })
    .catch(err => console.error('Failed to load message count:', err));
});
</script>
