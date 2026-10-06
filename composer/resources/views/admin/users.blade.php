@extends('app')

@section('title', 'Manage Users - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <style>
        .admin-user-btn {
            display: inline-block;
            background: var(--ag-gradient);
            color: var(--ag-ink);
            text-decoration: none;
            border: none;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .admin-user-btn:hover {
            background: linear-gradient(135deg, #5ff0c9 0%, #2fbaf2 100%) !important;
            color: #ffffff !important;
        }

        .admin-user-btn-sm {
            padding: 8px 12px;
            font-size: 12px;
        }
    </style>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">Manage Users</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration: none; color: var(--ag-teal);">← Back to Dashboard</a>
    </div>

    @if(session('success'))
        <div style="padding: 12px; border-radius: 12px; background: #f4f6f8; color: var(--ag-text); margin-bottom: 16px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-danger-soft); color: var(--ag-danger); margin-bottom: 16px;">
            <ul style="margin-left: 16px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ag-card" style="padding: 20px; margin-bottom: 16px;">
        <h2 style="font-size: 18px; margin-bottom: 10px; color: var(--ag-text);">Admin User Capabilities</h2>
        <p style="color: var(--ag-muted); margin-bottom: 12px;">Admins can use all user functionalities including PAT token management, system registration workflows, and configuration backups.</p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('settings') }}" class="admin-user-btn admin-user-btn-sm">PAT Tokens & User Settings</a>
            <a href="{{ route('systems-registered') }}" class="admin-user-btn admin-user-btn-sm">Systems Registered</a>
            <a href="{{ route('configuration-backups') }}" class="admin-user-btn admin-user-btn-sm">Configuration Backups</a>
        </div>
    </div>

    <div style="display: flex; justify-content: flex-end; margin-bottom: 16px;">
        <button type="button" id="openRegisterUserModal" class="admin-user-btn">Register User</button>
    </div>

    <div id="registerUserModal" style="display: none; position: fixed; inset: 0; background: rgba(17,24,39,0.45); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
        <div style="background: var(--ag-card); border-radius: 16px; width: min(760px, 100%); max-height: 90vh; overflow: auto; box-shadow: var(--ag-shadow);">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; border-bottom: 1px solid var(--ag-line);">
                <h2 style="font-size: 20px; color: var(--ag-text); font-weight: 500;">Register User</h2>
                <button type="button" id="closeRegisterUserModal" style="background: transparent; border: none; font-size: 20px; color: var(--ag-muted); cursor: pointer;">&times;</button>
            </div>

            <form method="POST" action="{{ route('admin.users.store') }}" style="padding: 20px;">
                @csrf
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label class="ag-label">Username</label>
                        <input class="ag-input" type="text" name="username" value="{{ old('username') }}" required style="width: 100%;">
                    </div>
                    <div>
                        <label class="ag-label">Email</label>
                        <input class="ag-input" type="email" name="email" value="{{ old('email') }}" required style="width: 100%;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label class="ag-label">First Name</label>
                        <input class="ag-input" type="text" name="first_name" value="{{ old('first_name') }}" required style="width: 100%;">
                    </div>
                    <div>
                        <label class="ag-label">Last Name</label>
                        <input class="ag-input" type="text" name="last_name" value="{{ old('last_name') }}" required style="width: 100%;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label class="ag-label">Password</label>
                        <input class="ag-input" type="password" name="password" required style="width: 100%;">
                    </div>
                    <div>
                        <label class="ag-label">Confirm Password</label>
                        <input class="ag-input" type="password" name="password_confirmation" required style="width: 100%;">
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="ag-label">Role</label>
                    <select class="ag-select" name="role" required style="width: 100%;">
                        <option value="user" {{ old('role') === 'user' ? 'selected' : '' }}>User</option>
                        <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                    </select>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="ag-label">Workspace</label>
                    <select class="ag-select" name="workspace_id" style="width: 100%;">
                        <option value="">Use selected header workspace</option>
                        @foreach(($workspaceSelectorWorkspaces ?? collect()) as $workspaceOption)
                            <option value="{{ $workspaceOption->id }}" {{ (string) old('workspace_id', $selectedWorkspaceId ?? '') === (string) $workspaceOption->id ? 'selected' : '' }}>
                                {{ $workspaceOption->name }}
                            </option>
                        @endforeach
                    </select>
                    <p style="margin-top: 6px; font-size: 12px; color: var(--ag-muted);">Works for both Admin and User roles.</p>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="cancelRegisterUserModal" class="admin-user-btn">Cancel</button>
                    <button type="submit" class="admin-user-btn">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="ag-card" style="padding: 24px;">
        <div style="margin-bottom: 20px;">
            <label class="ag-label">Search User or Admin</label>
            <div style="display: flex; gap: 10px;">
                <input class="ag-input" type="text" id="userSearchInput" placeholder="Search by name or email..." style="flex: 1;">
            </div>
        </div>

        <div class="tab-buttons" style="margin-bottom: 16px;">
            <button type="button" class="tab-btn active" data-user-tab="users" style="position: relative;">
                Users
                <span id="userCount" style="display: inline-block; margin-left: 8px; background: var(--ag-teal); color: white; border-radius: 999px; padding: 2px 8px; font-size: 11px; font-weight: 600;">{{ count($users) }}</span>
            </button>
            <button type="button" class="tab-btn" data-user-tab="admins" style="position: relative;">
                Admin
                <span id="adminCount" style="display: inline-block; margin-left: 8px; background: var(--ag-teal); color: white; border-radius: 999px; padding: 2px 8px; font-size: 11px; font-weight: 600;">{{ count($adminUsers) }}</span>
            </button>
        </div>

        <div class="tab-content active" id="users-list-tab" style="display: block;">
            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table class="ag-table" style="width: 100%; min-width: 600px;">
                    <thead>
                        <tr style="background: var(--ag-surface); border-bottom: 1px solid var(--ag-line);">
                            <th style="text-align: left; padding: 12px;">User ID</th>
                            <th style="text-align: left; padding: 12px;">Name</th>
                            <th style="text-align: left; padding: 12px;">Email</th>
                            <th style="text-align: left; padding: 12px;">Systems Registered</th>
                            <th style="text-align: left; padding: 12px;">Services</th>
                            <th style="text-align: left; padding: 12px;">Configs</th>
                            <th style="text-align: left; padding: 12px;">Status</th>
                            <th style="text-align: left; padding: 12px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $item)
                            <tr class="user-row" data-search="{{ strtolower($item->name . ' ' . $item->email) }}" style="border-bottom: 1px solid var(--ag-line);">
                                <td style="padding: 12px;">{{ $item->id }}</td>
                                <td style="padding: 12px;"><span style="display: inline-flex; align-items: center; gap: 8px;"><x-user-avatar :user="$item" size="28" />{{ $item->name }}</span></td>
                                <td style="padding: 12px;">{{ $item->email }}</td>
                                <td style="padding: 12px;">{{ $item->system_count }}</td>
                                <td style="padding: 12px;">{{ $item->service_count }}</td>
                                <td style="padding: 12px;">{{ $item->configuration_count }}</td>
                                <td style="padding: 12px;">
                                    @php $isActive = strtolower((string) $item->status) === 'active'; @endphp
                                    <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:{{ $isActive ? '#e3faf3' : '#fdecec' }}; color:{{ $isActive ? '#137a54' : '#b33b3b' }};">
                                        {{ ucfirst($item->status ?? 'unknown') }}
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <a href="{{ route('admin.users.profile', $item->id) }}" class="admin-user-btn admin-user-btn-sm">View User Profile</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" style="padding: 16px;">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px;">
                {{ $users->links() }}
            </div>
        </div>

        <div class="tab-content" id="admins-list-tab" style="display: none;">
            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table class="ag-table" style="width: 100%; min-width: 600px;">
                    <thead>
                        <tr style="background: var(--ag-surface); border-bottom: 1px solid var(--ag-line);">
                            <th style="text-align: left; padding: 12px;">Admin ID</th>
                            <th style="text-align: left; padding: 12px;">Name</th>
                            <th style="text-align: left; padding: 12px;">Email</th>
                            <th style="text-align: left; padding: 12px;">Systems Registered</th>
                            <th style="text-align: left; padding: 12px;">Services</th>
                            <th style="text-align: left; padding: 12px;">Configs</th>
                            <th style="text-align: left; padding: 12px;">Status</th>
                            <th style="text-align: left; padding: 12px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($adminUsers as $item)
                            <tr class="admin-row" data-search="{{ strtolower($item->name . ' ' . $item->email) }}" style="border-bottom: 1px solid var(--ag-line);">
                                <td style="padding: 12px;">{{ $item->id }}</td>
                                <td style="padding: 12px;"><span style="display: inline-flex; align-items: center; gap: 8px;"><x-user-avatar :user="$item" size="28" />{{ $item->name }}</span></td>
                                <td style="padding: 12px;">{{ $item->email }}</td>
                                <td style="padding: 12px;">{{ $item->system_count }}</td>
                                <td style="padding: 12px;">{{ $item->service_count }}</td>
                                <td style="padding: 12px;">{{ $item->configuration_count }}</td>
                                <td style="padding: 12px;">
                                    @php $isActive = strtolower((string) $item->status) === 'active'; @endphp
                                    <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:{{ $isActive ? '#e3faf3' : '#fdecec' }}; color:{{ $isActive ? '#137a54' : '#b33b3b' }};">
                                        {{ ucfirst($item->status ?? 'unknown') }}
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <a href="{{ route('admin.users.profile', $item->id) }}" class="admin-user-btn admin-user-btn-sm">View User Profile</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" style="padding: 16px;">No admins found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px;">
                {{ $adminUsers->links() }}
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabButtons = document.querySelectorAll('[data-user-tab]');
        const usersTab = document.getElementById('users-list-tab');
        const adminsTab = document.getElementById('admins-list-tab');
        const registerModal = document.getElementById('registerUserModal');
        const openRegisterModalButton = document.getElementById('openRegisterUserModal');
        const closeRegisterModalButton = document.getElementById('closeRegisterUserModal');
        const cancelRegisterModalButton = document.getElementById('cancelRegisterUserModal');

        tabButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const selectedTab = button.getAttribute('data-user-tab');

                tabButtons.forEach(function (btn) {
                    btn.classList.remove('active');
                });

                button.classList.add('active');

                if (selectedTab === 'admins') {
                    usersTab.style.display = 'none';
                    adminsTab.style.display = 'block';
                } else {
                    usersTab.style.display = 'block';
                    adminsTab.style.display = 'none';
                }
            });
        });

        if (openRegisterModalButton && registerModal) {
            openRegisterModalButton.addEventListener('click', function () {
                registerModal.style.display = 'flex';
            });
        }

        if (closeRegisterModalButton && registerModal) {
            closeRegisterModalButton.addEventListener('click', function () {
                registerModal.style.display = 'none';
            });
        }

        if (cancelRegisterModalButton && registerModal) {
            cancelRegisterModalButton.addEventListener('click', function () {
                registerModal.style.display = 'none';
            });
        }

        if (registerModal) {
            registerModal.addEventListener('click', function (event) {
                if (event.target === registerModal) {
                    registerModal.style.display = 'none';
                }
            });
        }

        // Search functionality
        const userSearchInput = document.getElementById('userSearchInput');
        const userRows = document.querySelectorAll('.user-row');
        const adminRows = document.querySelectorAll('.admin-row');
        const userCountBadge = document.getElementById('userCount');
        const adminCountBadge = document.getElementById('adminCount');

        if (userSearchInput) {
            userSearchInput.addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase();
                let visibleUserCount = 0;
                let visibleAdminCount = 0;

                userRows.forEach(row => {
                    const searchData = row.getAttribute('data-search');
                    if (searchTerm === '' || searchData.includes(searchTerm)) {
                        row.style.display = '';
                        visibleUserCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                adminRows.forEach(row => {
                    const searchData = row.getAttribute('data-search');
                    if (searchTerm === '' || searchData.includes(searchTerm)) {
                        row.style.display = '';
                        visibleAdminCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Update count badges
                if (userCountBadge) {
                    userCountBadge.textContent = visibleUserCount;
                    userCountBadge.style.background = searchTerm !== '' ? '#ef4444' : '#4f46e5';
                }
                if (adminCountBadge) {
                    adminCountBadge.textContent = visibleAdminCount;
                    adminCountBadge.style.background = searchTerm !== '' ? '#ef4444' : '#4f46e5';
                }
            });
        }

        @if($errors->any())
            if (registerModal) {
                registerModal.style.display = 'flex';
            }
        @endif
    });
</script>
@endsection
