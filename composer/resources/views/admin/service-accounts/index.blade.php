@extends('app')

@section('title', 'Service Accounts - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <h1 style="font-size: 30px; color: var(--ag-text); margin-bottom: 8px;">Service Accounts</h1>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 20px;">
        Machine identities for the <code>atglance</code> CLI. A server registered with a service account keeps working when the person who registered it leaves.
    </p>

    @include('admin.service-accounts.flash')

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Accounts</h2>
        <table class="ag-table" style="width: 100%;">
            <thead>
                <tr><th>Name</th><th>Scope</th><th>Workspaces</th><th>Owner</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse($accounts as $account)
                    <tr>
                        <td><a href="{{ route('service-accounts.show', $account->id) }}">{{ $account->name }}</a></td>
                        <td>{{ ucfirst($account->scope) }}</td>
                        <td>{{ $account->scope === 'workspace' ? $account->workspace?->name : $account->active_grants_count . ' granted' }}</td>
                        <td>
                            @if($account->owner)
                                {{ $account->owner->email }}
                            @else
                                <span class="status-badge status-inactive">No owner</span>
                            @endif
                        </td>
                        <td><span class="status-badge {{ $account->status === 'active' ? 'status-active' : 'status-inactive' }}">{{ ucfirst($account->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="color: var(--ag-muted);">No service accounts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Create service account</h2>
        <form method="POST" action="{{ route('service-accounts.store') }}" style="display: grid; gap: 12px; max-width: 520px;">
            @csrf
            <label>Name <input class="ag-input" type="text" name="name" maxlength="100" value="{{ old('name') }}" required></label>
            <label>Description <input class="ag-input" type="text" name="description" maxlength="512" value="{{ old('description') }}"></label>
            <label>Owning team <input class="ag-input" type="text" name="team" maxlength="100" value="{{ old('team') }}"></label>
            <label>Scope
                <select class="ag-input" name="scope" id="saScope">
                    <option value="organization" @selected(old('scope') === 'organization')>Organization (choose workspaces after creating)</option>
                    <option value="workspace" @selected(old('scope', 'workspace') === 'workspace')>One workspace</option>
                </select>
            </label>
            <label id="saWorkspaceField">Workspace
                <select class="ag-input" name="workspace_id">
                    @foreach($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected((int) old('workspace_id') === $workspace->id)>{{ $workspace->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="ag-btn" type="submit">Create</button>
        </form>
    </div>

    <div class="ag-card" style="padding: 24px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Settings</h2>
        <form method="POST" action="{{ route('service-accounts.settings') }}" style="display: grid; gap: 12px; max-width: 520px;">
            @csrf
            @method('PUT')
            <label>Default key lifetime (days) <input class="ag-input" type="number" name="sa_default_key_days" min="1" max="3650" value="{{ $settings['sa_default_key_days'] }}"></label>
            <label>Longest key lifetime (days) <input class="ag-input" type="number" name="sa_max_key_days" min="1" max="3650" value="{{ $settings['sa_max_key_days'] }}"></label>
            <label>Active organization accounts allowed <input class="ag-input" type="number" name="sa_org_quota" min="0" max="1000" value="{{ $settings['sa_org_quota'] }}"></label>
            <label>Active accounts allowed per workspace <input class="ag-input" type="number" name="sa_workspace_quota" min="0" max="1000" value="{{ $settings['sa_workspace_quota'] }}"></label>
            <label>Expiry notices, days before (comma-separated) <input class="ag-input" type="text" name="sa_expiry_notice_days" value="{{ $settings['sa_expiry_notice_days'] }}"></label>
            <label><input type="checkbox" name="sa_require_for_registration" value="1" @checked($settings['sa_require_for_registration'])> Require a service account key to register servers (turn on once every server's atglance CLI supports service accounts)</label>
            <button class="ag-btn" type="submit">Save settings</button>
        </form>
    </div>
</div>

<script>
    (function () {
        const scope = document.getElementById('saScope');
        const field = document.getElementById('saWorkspaceField');
        function refresh() { field.style.display = scope.value === 'workspace' ? 'block' : 'none'; }
        scope.addEventListener('change', refresh);
        refresh();
    })();
</script>
@endsection
