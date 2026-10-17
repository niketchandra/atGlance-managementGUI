@extends('app')

@section('title', 'Service Accounts - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <h1 style="font-size: 30px; color: var(--ag-text); margin-bottom: 8px;">Service Accounts</h1>
    <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 20px;">
        Machine identities for the <code>atglance</code> CLI. A server registered with a service account keeps working when the person who registered it leaves.
    </p>

    @include('admin.service-accounts.flash')

    @php
        $saPanes = [
            'accounts' => ['Accounts', 'fa-robot'],
            'settings' => ['Settings', 'fa-sliders-h'],
        ];
    @endphp

    <div class="ag-side-layout">
        <nav class="ag-tabs ag-tabs--vertical" aria-label="Service account sections">
            @foreach($saPanes as $paneKey => [$paneLabel, $paneIcon])
                <button type="button" class="ag-tab sa-pane-btn {{ $activePane === $paneKey ? 'active' : '' }}" data-pane="{{ $paneKey }}"><i class="fas {{ $paneIcon }}"></i> {{ $paneLabel }}</button>
            @endforeach
        </nav>
        <div class="ag-side-content">

            <div id="sa-pane-accounts" class="sa-pane" style="display: {{ $activePane === 'accounts' ? 'block' : 'none' }};">
                <div class="sa-split">
                    <div class="ag-card" style="padding: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px;">
                            <h2 style="font-size: 18px; color: var(--ag-text);">Accounts</h2>
                            <form method="GET" action="{{ route('service-accounts.index') }}" style="display: flex; gap: 8px; align-items: center;">
                                <label for="saFilter" style="font-size: 13px; color: var(--ag-muted);">Workspace</label>
                                <select class="ag-input" id="saFilter" name="workspace" onchange="this.form.submit()">
                                    <option value="0" @selected($filterWorkspace === 0)>All workspaces</option>
                                    @foreach($workspaces as $workspace)
                                        <option value="{{ $workspace->id }}" @selected($filterWorkspace === $workspace->id)>{{ $workspace->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </div>
                        <div style="overflow-x: auto;">
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
                                        <tr><td colspan="5" style="color: var(--ag-muted);">No service accounts here yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="ag-card" style="padding: 24px;">
                        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Create service account</h2>
                        <form method="POST" action="{{ route('service-accounts.store') }}" style="display: grid; gap: 12px;">
                            @csrf
                            <label>Name <input class="ag-input" type="text" name="name" maxlength="100" value="{{ old('name') }}" required style="width: 100%;"></label>
                            <label>Description <input class="ag-input" type="text" name="description" maxlength="512" value="{{ old('description') }}" style="width: 100%;"></label>
                            <label>Owning team <input class="ag-input" type="text" name="team" maxlength="100" value="{{ old('team') }}" style="width: 100%;"></label>
                            <label>Scope
                                <select class="ag-input" name="scope" id="saScope" style="width: 100%;">
                                    <option value="workspace" @selected(old('scope', 'workspace') === 'workspace')>Workspace</option>
                                    <option value="organization" @selected(old('scope') === 'organization')>Organization (choose workspaces after creating)</option>
                                </select>
                            </label>
                            <label id="saWorkspaceField">Workspace
                                <select class="ag-input" name="workspace_id" style="width: 100%;">
                                    <option value="all" @selected(old('workspace_id') === 'all')>All workspaces (organization account with access to each)</option>
                                    @foreach($workspaces as $workspace)
                                        <option value="{{ $workspace->id }}" @selected((string) old('workspace_id') === (string) $workspace->id)>{{ $workspace->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="ag-btn" type="submit">Create</button>
                        </form>
                    </div>
                </div>
            </div>

            <div id="sa-pane-settings" class="sa-pane ag-card" style="padding: 24px; display: {{ $activePane === 'settings' ? 'block' : 'none' }};">
                <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 12px;">Settings</h2>
                <form method="POST" action="{{ route('service-accounts.settings') }}" style="display: grid; gap: 12px; max-width: 520px;">
                    @csrf
                    @method('PUT')
                    <h3 style="font-size: 14px; color: var(--ag-muted); text-transform: uppercase; letter-spacing: 0.4px;">Service account keys</h3>
                    <label>Default key lifetime (days) <input class="ag-input" type="number" name="sa_default_key_days" min="1" max="3650" value="{{ $settings['sa_default_key_days'] }}"></label>
                    <label>Longest key lifetime (days) <input class="ag-input" type="number" name="sa_max_key_days" min="1" max="3650" value="{{ $settings['sa_max_key_days'] }}"></label>
                    <label>Active organization accounts allowed <input class="ag-input" type="number" name="sa_org_quota" min="0" max="1000" value="{{ $settings['sa_org_quota'] }}"></label>
                    <label>Active accounts allowed per workspace <input class="ag-input" type="number" name="sa_workspace_quota" min="0" max="1000" value="{{ $settings['sa_workspace_quota'] }}"></label>
                    <label><input type="checkbox" name="sa_require_for_registration" value="1" @checked($settings['sa_require_for_registration'])> Require a service account key to register servers (turn on once every server's atglance CLI supports service accounts)</label>

                    <h3 style="font-size: 14px; color: var(--ag-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-top: 8px;">Personal API keys</h3>
                    <label>Default lifetime (days) <input class="ag-input" type="number" name="pat_default_key_days" min="1" max="3650" value="{{ $settings['pat_default_key_days'] }}"></label>
                    <label>Longest lifetime (days) <input class="ag-input" type="number" name="pat_max_key_days" min="1" max="3650" value="{{ $settings['pat_max_key_days'] }}"></label>
                    <label>Active keys per user <input class="ag-input" type="number" name="pat_user_quota" min="1" max="100" value="{{ $settings['pat_user_quota'] }}"></label>

                    <h3 style="font-size: 14px; color: var(--ag-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-top: 8px;">Expiry notices</h3>
                    <label>Days before expiry (comma-separated, 0 = expiry day) <input class="ag-input" type="text" name="sa_expiry_notice_days" value="{{ $settings['sa_expiry_notice_days'] }}"></label>
                    <button class="ag-btn" type="submit">Save settings</button>
                </form>
            </div>

        </div>
    </div>
</div>

<style>
    .sa-split { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 20px; align-items: start; }
    @media (max-width: 1024px) { .sa-split { grid-template-columns: minmax(0, 1fr); } }
</style>

<script>
    (function () {
        const buttons = document.querySelectorAll('.sa-pane-btn');
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                const pane = button.dataset.pane;
                buttons.forEach(b => b.classList.toggle('active', b === button));
                document.querySelectorAll('.sa-pane').forEach(function (panel) {
                    panel.style.display = panel.id === 'sa-pane-' + pane ? 'block' : 'none';
                });
                const url = new URL(window.location.href);
                url.searchParams.set('pane', pane);
                history.replaceState(null, '', url);
            });
        });

        const scope = document.getElementById('saScope');
        const field = document.getElementById('saWorkspaceField');
        function refresh() { field.style.display = scope.value === 'workspace' ? 'block' : 'none'; }
        scope.addEventListener('change', refresh);
        refresh();
    })();
</script>
@endsection
