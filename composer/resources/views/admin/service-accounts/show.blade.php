@extends('app')

@section('title', 'Service Account - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">{{ $account->name }}</h1>
        <span class="status-badge {{ $account->status === 'active' ? 'status-active' : 'status-inactive' }}">{{ ucfirst($account->status) }}</span>
    </div>

    @include('admin.service-accounts.flash')

    @if($newKey)
        <div class="ag-card" style="padding: 20px; margin-bottom: 20px; border: 2px solid var(--ag-success);">
            <strong>New key. Copy it now: it will not be shown again.</strong>
            <pre style="margin-top: 8px; user-select: all; white-space: pre-wrap; word-break: break-all;">{{ $newKey }}</pre>
            <p style="font-size: 13px; color: var(--ag-muted);">On the server: <code>sudo atglance --configure</code>, then paste this as the service account key.</p>
        </div>
    @endif

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Details</h2>
        <p>Scope: <strong>{{ ucfirst($account->scope) }}</strong>@if($account->workspace) · Workspace: <strong>{{ $account->workspace->name }}</strong>@endif</p>
        <p>Owner: <strong>{{ $account->owner?->email ?? 'No owner' }}</strong></p>
        <form method="POST" action="{{ route('service-accounts.update', $account->id) }}" style="display: grid; gap: 10px; max-width: 520px; margin-top: 12px;">
            @csrf
            @method('PUT')
            <label>Name <input class="ag-input" type="text" name="name" maxlength="100" value="{{ old('name', $account->name) }}" required></label>
            <label>Description <input class="ag-input" type="text" name="description" maxlength="512" value="{{ old('description', $account->description) }}"></label>
            <label>Owning team <input class="ag-input" type="text" name="team" maxlength="100" value="{{ old('team', $account->team) }}"></label>
            <button class="ag-btn" type="submit">Save</button>
        </form>
        <form method="POST" action="{{ route('service-accounts.owner', $account->id) }}" style="display: flex; gap: 10px; margin-top: 12px;">
            @csrf
            <select class="ag-input" name="owner_user_id" aria-label="Owner">
                @foreach($possibleOwners as $candidate)
                    <option value="{{ $candidate->id }}" @selected($candidate->id === (int) $account->owner_user_id)>{{ $candidate->email }}</option>
                @endforeach
            </select>
            <button class="ag-btn ag-btn--ghost" type="submit">Change owner</button>
        </form>
        <form method="POST" action="{{ route('service-accounts.status', $account->id) }}" style="margin-top: 12px;">
            @csrf
            <input type="hidden" name="active" value="{{ $account->status === 'active' ? 0 : 1 }}">
            <button class="ag-btn ag-btn--ghost" type="submit">{{ $account->status === 'active' ? 'Disable (all keys stop working)' : 'Enable' }}</button>
        </form>
    </div>

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Workspace access</h2>
        <table class="ag-table" style="width: 100%;">
            <thead><tr><th>Workspace</th><th>Permissions</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @foreach($account->grants as $grant)
                    <tr>
                        <td>{{ $grant->workspace?->name }}</td>
                        <td>
                            <form method="POST" action="{{ route('service-accounts.grants.store', $account->id) }}" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                                @csrf
                                <input type="hidden" name="workspace_id" value="{{ $grant->workspace_id }}">
                                @foreach(\App\Models\ServiceAccount::PERMISSIONS as $permissionKey => $permissionLabel)
                                    <label title="{{ $permissionLabel }}"><input type="checkbox" name="permissions[]" value="{{ $permissionKey }}" @checked(in_array($permissionKey, (array) $grant->permissions, true))> {{ $permissionKey }}</label>
                                @endforeach
                                <button class="ag-btn ag-btn--ghost" type="submit">Save</button>
                            </form>
                        </td>
                        <td>{{ ucfirst($grant->status) }}</td>
                        <td>
                            @if($account->scope === 'organization' && $grant->status === 'active')
                                <form method="POST" action="{{ route('service-accounts.grants.destroy', [$account->id, $grant->workspace_id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="ag-btn ag-btn--ghost" type="submit">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($grantableWorkspaces->isNotEmpty())
            <form method="POST" action="{{ route('service-accounts.grants.store', $account->id) }}" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px;">
                @csrf
                <select class="ag-input" name="workspace_id" aria-label="Workspace">
                    @foreach($grantableWorkspaces as $grantWorkspace)
                        <option value="{{ $grantWorkspace->id }}">{{ $grantWorkspace->name }}</option>
                    @endforeach
                </select>
                @foreach(\App\Models\ServiceAccount::PERMISSIONS as $permissionKey => $permissionLabel)
                    <label title="{{ $permissionLabel }}"><input type="checkbox" name="permissions[]" value="{{ $permissionKey }}" @checked(in_array($permissionKey, \App\Models\ServiceAccount::DEFAULT_PERMISSIONS, true))> {{ $permissionKey }}</label>
                @endforeach
                <button class="ag-btn" type="submit">Give access</button>
            </form>
        @endif
    </div>

    <div class="ag-card" style="padding: 24px;">
        <h2 style="font-size: 18px; margin-bottom: 12px;">Keys ({{ $activeKeyCount }} of 2 active)</h2>
        <table class="ag-table" style="width: 100%;">
            <thead><tr><th>Name</th><th>Key</th><th>Expires</th><th>Last used</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($keys as $key)
                    @php($expired = $key->expires_at && $key->expires_at->isPast())
                    <tr>
                        <td>{{ $key->name }}</td>
                        <td><code>atgsa-…{{ $key->last_four }}</code></td>
                        <td>{{ $key->expires_at?->toDateString() }}</td>
                        <td>{{ $key->last_used_at?->diffForHumans() ?? 'never' }}</td>
                        <td>{{ $key->status === 'revoked' ? 'Revoked: ' . $key->revoke_reason : ($expired ? 'Expired' : 'Active') }}</td>
                        <td>
                            @if($key->status === 'active' && !$expired)
                                <form method="POST" action="{{ route('service-accounts.keys.revoke', [$account->id, $key->id]) }}" style="display: flex; gap: 6px;">
                                    @csrf
                                    <input class="ag-input" type="text" name="reason" maxlength="50" placeholder="Reason" required aria-label="Reason">
                                    <button class="ag-btn ag-btn--ghost" type="submit">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="color: var(--ag-muted);">No keys yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <form method="POST" action="{{ route('service-accounts.keys.store', $account->id) }}" style="display: flex; gap: 10px; margin-top: 12px;">
            @csrf
            <input class="ag-input" type="text" name="name" maxlength="100" placeholder="Key name, e.g. fleet-a" required aria-label="Key name">
            <input class="ag-input" type="number" name="days" min="1" max="{{ $keyDays['max'] }}" value="{{ $keyDays['default'] }}" style="width: 120px;" aria-label="Days valid">
            <button class="ag-btn" type="submit">Create key</button>
        </form>
        <p style="font-size: 13px; color: var(--ag-muted); margin-top: 8px;">To rotate: create a second key, move the servers to it, then revoke the old key.</p>
    </div>
</div>
@endsection
