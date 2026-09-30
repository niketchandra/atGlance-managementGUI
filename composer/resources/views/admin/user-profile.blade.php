@extends('app')

@section('title', 'User Profile - Admin - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <style>
        .user-profile-btn {
            display: inline-block;
            background: var(--ag-gradient);
            color: var(--ag-ink);
            border: none;
            border-radius: 999px;
            padding: 10px 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .user-profile-btn:hover {
            background: linear-gradient(135deg, #5ff0c9 0%, #2fbaf2 100%) !important;
            color: #ffffff !important;
        }
    </style>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">User Profile</h1>
        <a href="{{ route('admin.users') }}" style="text-decoration: none; color: var(--ag-text);">← Back to Users</a>
    </div>

    @if(session('success'))
        <div style="padding: 12px; border-radius: 12px; background: #f4f6f8; color: var(--ag-text); margin-bottom: 16px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding: 12px; border-radius: 12px; background: #dcdcdc; color: var(--ag-text); margin-bottom: 16px;">
            <ul style="margin: 0; padding-left: 18px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ag-card" style="padding: 24px; margin-bottom: 20px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase; margin-bottom: 6px;">User ID</div>
                <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $user->id }}</div>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase; margin-bottom: 6px;">Status</div>
                @php $isActive = strtolower((string) $user->status) === 'active'; @endphp
                <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:{{ $isActive ? '#e7e7e7' : '#d4d4d4' }}; color:#222222;">{{ ucfirst($user->status ?? 'unknown') }}</span>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase; margin-bottom: 6px;">Name</div>
                <div style="display: flex; align-items: center; gap: 10px; font-size: 16px; color: var(--ag-text); font-weight: 700;"><x-user-avatar :user="$user" size="40" />{{ $user->name }}</div>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase; margin-bottom: 6px;">Email</div>
                <div style="font-size: 16px; color: var(--ag-text); font-weight: 700;">{{ $user->email }}</div>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px;">
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Systems Registered</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--ag-text);">{{ $stats['systems'] }}</div>
        </div>
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Services</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--ag-text);">{{ $stats['services'] }}</div>
        </div>
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Configs</div>
            <div style="font-size: 24px; font-weight: 700; color: var(--ag-text);">{{ $stats['configurations'] }}</div>
        </div>
    </div>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="#edit-user-profile" class="user-profile-btn">Open User Profile</a>
        <a href="{{ route('systems-registered') }}" class="user-profile-btn">Open Systems Registered</a>
        <a href="{{ route('configuration-backups') }}" class="user-profile-btn">Open Configuration Backups</a>
    </div>

    <div class="ag-card" id="edit-user-profile" style="padding: 24px; margin-top: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 20px;">
            <div>
                <h2 style="font-size: 22px; color: var(--ag-text); margin: 0 0 8px 0;">Edit User Profile</h2>
                @if($canEditUserProfile)
                    <p style="color: var(--ag-muted); margin: 0;">Update the user's details, account status, and access role from this page.</p>
                @else
                    <p style="color: var(--ag-muted); margin: 0;">This profile is read only. Admin users cannot modify another admin profile.</p>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('admin.users.update', ['user' => $user->id]) }}">
            @csrf
            @method('PUT')

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="ag-label" for="username">Username</label>
                    <input class="ag-input" id="username" type="text" name="username" value="{{ old('username', $user->name) }}" {{ $canEditUserProfile ? '' : 'readonly' }} required style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label" for="email">Email</label>
                    <input class="ag-input" id="email" type="email" name="email" value="{{ old('email', $user->email) }}" {{ $canEditUserProfile ? '' : 'readonly' }} required style="width: 100%;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="ag-label" for="first_name">First Name</label>
                    <input class="ag-input" id="first_name" type="text" name="first_name" value="{{ old('first_name', $user->first_name) }}" {{ $canEditUserProfile ? '' : 'readonly' }} style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label" for="last_name">Last Name</label>
                    <input class="ag-input" id="last_name" type="text" name="last_name" value="{{ old('last_name', $user->last_name) }}" {{ $canEditUserProfile ? '' : 'readonly' }} style="width: 100%;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <label class="ag-label" for="status">Status</label>
                    <select class="ag-select" id="status" name="status" {{ $canEditUserProfile ? '' : 'disabled' }} required style="width: 100%;">
                        <option value="active" {{ old('status', $user->status) === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status', $user->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    @if(!$canEditUserProfile)
                        <input type="hidden" name="status" value="{{ old('status', $user->status) }}">
                    @endif
                </div>
                <div>
                    <label class="ag-label" for="role">Role</label>
                    <select class="ag-select" id="role" name="role" {{ $canEditUserProfile ? '' : 'disabled' }} required style="width: 100%;">
                        <option value="user" {{ old('role', in_array((int) $user->rbac_id, [100, 101], true) ? 'admin' : 'user') === 'user' ? 'selected' : '' }}>User</option>
                        <option value="admin" {{ old('role', in_array((int) $user->rbac_id, [100, 101], true) ? 'admin' : 'user') === 'admin' ? 'selected' : '' }}>Admin</option>
                    </select>
                    @if(!$canEditUserProfile)
                        <input type="hidden" name="role" value="{{ old('role', in_array((int) $user->rbac_id, [100, 101], true) ? 'admin' : 'user') }}">
                    @endif
                </div>
            </div>

            @if($canEditUserProfile)
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="user-profile-btn">Save User Profile</button>
                </div>
            @endif
        </form>

        <div style="margin-top: 20px; border-top: 1px solid var(--ag-line); padding-top: 20px;">
            <h3 style="font-size: 18px; color: var(--ag-text); margin: 0 0 10px 0;">Workspace Assigned</h3>
            <p style="color: var(--ag-muted); margin: 0 0 12px 0; font-size: 13px;">Read only workspace membership for this user.</p>

            @if(($assignedWorkspaces ?? collect())->isEmpty())
                <div style="padding: 12px; background: var(--ag-surface); border-radius: 12px; color: var(--ag-muted); font-size: 13px;">
                    No workspace assigned.
                </div>
            @else
                <ul style="margin: 0; padding-left: 18px; color: var(--ag-text);">
                    @foreach($assignedWorkspaces as $assignedWorkspace)
                        <li style="margin-bottom: 6px;">
                            {{ $assignedWorkspace->name }}
                            @if((bool) ($assignedWorkspace->pivot->is_admin ?? false))
                                <span style="font-size: 12px; color: var(--ag-subtle);">(Workspace Admin)</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="ag-card" style="padding: 24px; margin-top: 20px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin: 0 0 12px 0;"><i class="fas fa-history"></i> Recent Activity</h2>
        @include('partials.activity-list', ['activityItems' => $recentActivity, 'activityEmpty' => 'No activity recorded for this user yet.'])
    </div>
</div>
@endsection
