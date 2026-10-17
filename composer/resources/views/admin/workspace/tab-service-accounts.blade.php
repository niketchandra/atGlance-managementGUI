{{-- Service accounts tab: this workspace's accounts, plus organization accounts with access here (read-only). --}}
<div class="ag-card" style="padding: 24px;">
    <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Service Accounts</h2>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 16px;">Machine identities the <code>atglance</code> CLI uses on servers of this workspace.</p>

    <table class="ag-table" style="width: 100%; margin-bottom: 16px;">
        <thead><tr><th>Name</th><th>Scope</th><th>Owner</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($serviceAccountsTab['accounts'] as $saRow)
                <tr>
                    <td>
                        @if($saRow->scope === 'workspace' && $serviceAccountsTab['canCreate'])
                            <a href="{{ route('service-accounts.show', $saRow->id) }}">{{ $saRow->name }}</a>
                        @else
                            {{ $saRow->name }}
                        @endif
                    </td>
                    <td>{{ $saRow->scope === 'workspace' ? 'This workspace' : 'Organization (read-only here)' }}</td>
                    <td>{{ $saRow->owner?->email ?? 'No owner' }}</td>
                    <td>{{ ucfirst($saRow->status) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="color: var(--ag-muted);">No service accounts in this workspace.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($serviceAccountsTab['canCreate'])
        <form method="POST" action="{{ route('service-accounts.store') }}" style="display: grid; gap: 10px; max-width: 520px;">
            @csrf
            <input type="hidden" name="scope" value="workspace">
            <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
            <label>Name <input class="ag-input" type="text" name="name" maxlength="100" required></label>
            <label>Owning team <input class="ag-input" type="text" name="team" maxlength="100"></label>
            <button class="ag-btn" type="submit">Create service account</button>
        </form>
    @endif
</div>
