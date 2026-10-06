{{-- Members tab: add a workspace admin or user, and one table of everyone in the workspace. --}}
@php
    $members = $admins->map(fn ($member) => ['user' => $member, 'isAdmin' => true])
        ->concat($regularUsers->map(fn ($member) => ['user' => $member, 'isAdmin' => false]));
    $accountRoles = [100 => 'Super admin', 101 => 'Admin', 102 => 'User'];
@endphp

<div class="ws-member-forms">
    @if($canManageAdmins)
        <div class="ag-card ws-member-card">
            <h2>Add Workspace Admin</h2>
            <p>Any user or admin can be a workspace admin. A current member is promoted.</p>

            <form method="POST" action="{{ route($workspaceAddAdminRouteName, $workspace->id) }}">
                @csrf
                <label class="ag-label" for="adminSearchInput">Search</label>
                <input class="ag-input" id="adminSearchInput" type="text" autocomplete="off" placeholder="Type name or email">
                <input id="adminIdInput" type="hidden" name="admin_id" required>
                <div id="adminSelectedTag" class="ws-selected"></div>
                <div id="adminResults" class="ws-results"></div>
                @if($canSetPermissions)
                    @include('admin.workspace.permission-checkboxes', ['chosen' => \App\Models\Workspace::resolvePermissions(null), 'idPrefix' => 'new', 'legend' => 'Access if this person has the User role'])
                @endif
                <button class="ag-btn" type="submit">Add Admin</button>
            </form>
        </div>
    @endif

    @if($permissions['members'])
    <div class="ag-card ws-member-card">
        <h2>Add User</h2>
        <p>Users see this workspace's systems, config backups and dashboards.</p>

        <form method="POST" action="{{ route($workspaceAddUserRouteName, $workspace->id) }}">
            @csrf
            <label class="ag-label" for="userSearchInput">Search</label>
            <input class="ag-input" id="userSearchInput" type="text" autocomplete="off" placeholder="Type name or email">
            <input id="userIdInput" type="hidden" name="user_id" required>
            <div id="userSelectedTag" class="ws-selected"></div>
            <div id="userResults" class="ws-results"></div>
            <button class="ag-btn" type="submit">Add User</button>
        </form>
    </div>
    @endif
</div>

<div class="ag-card" style="padding: 0; overflow: hidden;">
    <div style="padding: 18px 20px; display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin: 0;">Members</h2>
        <span style="font-size: 13px; color: var(--ag-muted);">{{ $admins->count() }} {{ \Illuminate\Support\Str::plural('admin', $admins->count()) }} &middot; {{ $regularUsers->count() }} {{ \Illuminate\Support\Str::plural('user', $regularUsers->count()) }}</span>
    </div>

    @if($members->isEmpty())
        <p style="padding: 0 20px 20px; font-size: 13px; color: var(--ag-muted);">No members yet.</p>
    @else
        <div style="overflow-x: auto;">
            <table class="ws-member-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Workspace role</th>
                        <th>Account role</th>
                        <th>Access</th>
                        <th class="ws-actions">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($members as ['user' => $member, 'isAdmin' => $isWorkspaceAdmin])
                        <tr>
                            <td>
                                <span class="ws-member-name"><x-user-avatar :user="$member" size="28" />{{ $member->name }}</span>
                            </td>
                            <td class="ws-muted">{{ $member->email }}</td>
                            <td>
                                <span class="ws-role {{ $isWorkspaceAdmin ? 'ws-role--admin' : '' }}">{{ $isWorkspaceAdmin ? 'Workspace admin' : 'User' }}</span>
                            </td>
                            <td class="ws-muted">{{ $accountRoles[(int) $member->rbac_id] ?? 'User' }}</td>
                            <td>
                                @if(!$isWorkspaceAdmin)
                                    <span class="ws-muted">&mdash;</span>
                                @elseif((int) $member->rbac_id !== 102)
                                    <span class="ws-muted">Full</span>
                                @else
                                    @php($memberAccess = $adminPermissions[$member->id] ?? \App\Models\Workspace::resolvePermissions(null))
                                    <details class="ws-access">
                                        <summary>{{ count(array_filter($memberAccess)) }} of {{ count($memberAccess) }}{{ $canSetPermissions ? ' · Edit' : '' }}</summary>
                                        @if($canSetPermissions)
                                            <form method="POST" action="{{ route('admin.workspaces.users.permissions', [$workspace->id, $member->id]) }}">
                                                @csrf
                                                @method('PUT')
                                                @include('admin.workspace.permission-checkboxes', ['chosen' => $memberAccess, 'idPrefix' => 'm' . $member->id, 'legend' => 'Can change'])
                                                <button class="ag-btn" type="submit" style="margin-top: 8px;">Save access</button>
                                            </form>
                                        @else
                                            <ul style="margin: 6px 0 0 16px; font-size: 12px;">
                                                @foreach(\App\Models\Workspace::PERMISSIONS as $permissionKey => $permission)
                                                    <li style="color: {{ $memberAccess[$permissionKey] ? 'var(--ag-text)' : 'var(--ag-muted)' }};">{{ $memberAccess[$permissionKey] ? 'Yes' : 'No' }}: {{ $permission['label'] }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </details>
                                @endif
                            </td>
                            <td class="ws-actions">
                                @if(in_array((int) $member->id, $removableMemberIds, true))
                                    <form method="POST" action="{{ route($workspaceRemoveUserRouteName, [$workspace->id, $member->id]) }}" style="margin: 0;">
                                        @csrf
                                        @method('DELETE')
                                        <button class="ag-btn ag-btn--danger" type="submit" onclick="return confirm('Remove {{ $member->name }} from this workspace?')">Remove</button>
                                    </form>
                                @else
                                    <span class="ws-muted" title="{{ (int) $member->id === (int) auth()->id() ? 'You cannot remove yourself' : 'You cannot remove this member' }}">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<style>
    .ws-member-forms { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; margin-bottom: 18px; }
    .ws-member-card { padding: 20px; display: flex; flex-direction: column; }
    .ws-member-card h2 { font-size: 16px; color: var(--ag-text); margin: 0 0 6px; }
    .ws-member-card p { font-size: 13px; color: var(--ag-muted); margin: 0 0 14px; }
    .ws-member-card form { display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .ws-member-card form .ag-input { width: 100%; }
    .ws-member-card form .ag-btn { margin-top: auto; align-self: flex-start; }
    .ws-selected { display: none; font-size: 12px; color: var(--ag-text); background: var(--ag-surface); border-radius: 999px; padding: 6px 10px; width: fit-content; }
    .ws-results { display: none; border-radius: 12px; max-height: 180px; overflow: auto; background: var(--ag-card); border: 1px solid var(--ag-line); }
    .ws-member-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .ws-member-table th { text-align: left; padding: 10px 20px; background: var(--ag-surface); color: var(--ag-muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid var(--ag-line); }
    .ws-member-table td { padding: 10px 20px; border-bottom: 1px solid var(--ag-line); color: var(--ag-text); vertical-align: middle; }
    .ws-member-table tr:last-child td { border-bottom: 0; }
    .ws-member-table .ws-actions { text-align: right; white-space: nowrap; }
    .ws-member-name { display: inline-flex; align-items: center; gap: 10px; font-weight: 500; }
    .ws-muted { color: var(--ag-muted) !important; }
    .ws-role { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; background: var(--ag-surface); color: var(--ag-text); }
    .ws-role--admin { background: #1fa874; color: #fff; }
    .ws-access summary { cursor: pointer; font-size: 12px; color: var(--ag-teal); white-space: nowrap; }
    .ws-access[open] { min-width: 220px; }
    .ws-member-table td { overflow-wrap: anywhere; }
    .ws-perms { display: grid; gap: 6px; margin: 8px 0 0; padding: 10px 12px; background: var(--ag-surface); border: 0; border-radius: 10px; }
    .ws-perms legend { font-size: 12px; font-weight: 600; color: var(--ag-text); padding: 0; float: left; width: 100%; margin-bottom: 4px; }
    .ws-perms label { display: flex; gap: 8px; align-items: center; font-size: 12px; color: var(--ag-text); cursor: pointer; }
</style>
