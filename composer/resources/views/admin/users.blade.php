@extends('app')

@section('title', 'Manage Users - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <style>
        .admin-user-btn {
            display: inline-block;
            background: #000000;
            color: #ffffff;
            text-decoration: none;
            border: none;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .admin-user-btn:hover {
            background: #555555 !important;
            color: #ffffff !important;
        }

        .admin-user-btn-sm {
            padding: 8px 12px;
            font-size: 12px;
        }
    </style>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:28px; color:#111827;">Manage Users</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration:none; color:#4f46e5;">← Back to Dashboard</a>
    </div>

    @if(session('success'))
        <div style="padding:12px; border-radius:8px; background:#e6e6e6; color:#222222; border:1px solid #b3b3b3; margin-bottom:16px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding:12px; border-radius:8px; background:#fee2e2; color:#991b1b; margin-bottom:16px;">
            <ul style="margin-left:16px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="background:white; border-radius:10px; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.1); margin-bottom:16px;">
        <h2 style="font-size:18px; margin-bottom:10px; color:#111827;">Admin User Capabilities</h2>
        <p style="color:#6b7280; margin-bottom:12px;">Admins can use all user functionalities including PAT token management, system registration workflows, and configuration backups.</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('settings') }}" class="admin-user-btn admin-user-btn-sm">PAT Tokens & User Settings</a>
            <a href="{{ route('systems-registered') }}" class="admin-user-btn admin-user-btn-sm">Systems Registered</a>
            <a href="{{ route('configuration-backups') }}" class="admin-user-btn admin-user-btn-sm">Configuration Backups</a>
        </div>
    </div>

    <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
        <button type="button" id="openRegisterUserModal" class="admin-user-btn">Register User</button>
    </div>

    <div id="registerUserModal" style="display:none; position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:9999; align-items:center; justify-content:center; padding:20px;">
        <div style="background:white; border-radius:12px; width:min(760px, 100%); max-height:90vh; overflow:auto; box-shadow:0 16px 40px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid #e5e7eb;">
                <h2 style="font-size:20px; color:#111827; font-weight:700;">Register User</h2>
                <button type="button" id="closeRegisterUserModal" style="background:transparent; border:none; font-size:20px; color:#6b7280; cursor:pointer;">&times;</button>
            </div>

            <form method="POST" action="{{ route('admin.users.store') }}" style="padding:20px;">
                @csrf
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Username</label>
                        <input type="text" name="username" value="{{ old('username') }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">First Name</label>
                        <input type="text" name="first_name" value="{{ old('first_name') }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Last Name</label>
                        <input type="text" name="last_name" value="{{ old('last_name') }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Password</label>
                        <input type="password" name="password" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Confirm Password</label>
                        <input type="password" name="password_confirmation" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Role</label>
                    <select name="role" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; background:white;">
                        <option value="user" {{ old('role') === 'user' ? 'selected' : '' }}>User</option>
                        <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                    </select>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Workspace</label>
                    <select name="workspace_id" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; background:white;">
                        <option value="">Use selected header workspace</option>
                        @foreach(($workspaceSelectorWorkspaces ?? collect()) as $workspaceOption)
                            <option value="{{ $workspaceOption->id }}" {{ (string) old('workspace_id', $selectedWorkspaceId ?? '') === (string) $workspaceOption->id ? 'selected' : '' }}>
                                {{ $workspaceOption->name }}
                            </option>
                        @endforeach
                    </select>
                    <p style="margin-top:6px; font-size:12px; color:#6b7280;">Works for both Admin and User roles.</p>
                </div>

                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" id="cancelRegisterUserModal" class="admin-user-btn">Cancel</button>
                    <button type="submit" class="admin-user-btn">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div style="background:white; border-radius:10px; padding:24px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
        <div style="margin-bottom:20px;">
            <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:8px;">Search User or Admin</label>
            <div style="display:flex; gap:10px;">
                <input type="text" id="userSearchInput" placeholder="Search by name or email..." style="flex:1; border:1px solid #d1d5db; border-radius:8px; padding:10px 12px; font-size:13px;">
            </div>
        </div>

        <div class="tab-buttons" style="margin-bottom:16px;">
            <button type="button" class="tab-btn active" data-user-tab="users" style="position:relative;">
                Users
                <span id="userCount" style="display:inline-block; margin-left:8px; background:#4f46e5; color:white; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:600;">{{ count($users) }}</span>
            </button>
            <button type="button" class="tab-btn" data-user-tab="admins" style="position:relative;">
                Admin
                <span id="adminCount" style="display:inline-block; margin-left:8px; background:#4f46e5; color:white; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:600;">{{ count($adminUsers) }}</span>
            </button>
        </div>

        <div class="tab-content active" id="users-list-tab" style="display:block;">
            <div style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
                <table style="width:100%; border-collapse:collapse; min-width:600px;">
                    <thead>
                        <tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb;">
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">User ID</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Name</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Email</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Systems Registered</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Services</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Configs</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Status</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $item)
                            <tr class="user-row" data-search="{{ strtolower($item->name . ' ' . $item->email) }}" style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px;">{{ $item->id }}</td>
                                <td style="padding:12px;">{{ $item->name }}</td>
                                <td style="padding:12px;">{{ $item->email }}</td>
                                <td style="padding:12px;">{{ $item->system_count }}</td>
                                <td style="padding:12px;">{{ $item->service_count }}</td>
                                <td style="padding:12px;">{{ $item->configuration_count }}</td>
                                <td style="padding:12px;">
                                    @php $isActive = strtolower((string) $item->status) === 'active'; @endphp
                                    <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:{{ $isActive ? '#dcfce7' : '#fee2e2' }}; color:{{ $isActive ? '#166534' : '#991b1b' }};">
                                        {{ ucfirst($item->status ?? 'unknown') }}
                                    </span>
                                </td>
                                <td style="padding:12px;">
                                    <a href="{{ route('admin.users.profile', $item->id) }}" class="admin-user-btn admin-user-btn-sm">View User Profile</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" style="padding:16px; color:#6b7280;">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px;">
                {{ $users->links() }}
            </div>
        </div>

        <div class="tab-content" id="admins-list-tab" style="display:none;">
            <div style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
                <table style="width:100%; border-collapse:collapse; min-width:600px;">
                    <thead>
                        <tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb;">
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Admin ID</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Name</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Email</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Systems Registered</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Services</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Configs</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Status</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($adminUsers as $item)
                            <tr class="admin-row" data-search="{{ strtolower($item->name . ' ' . $item->email) }}" style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px;">{{ $item->id }}</td>
                                <td style="padding:12px;">{{ $item->name }}</td>
                                <td style="padding:12px;">{{ $item->email }}</td>
                                <td style="padding:12px;">{{ $item->system_count }}</td>
                                <td style="padding:12px;">{{ $item->service_count }}</td>
                                <td style="padding:12px;">{{ $item->configuration_count }}</td>
                                <td style="padding:12px;">
                                    @php $isActive = strtolower((string) $item->status) === 'active'; @endphp
                                    <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:{{ $isActive ? '#dcfce7' : '#fee2e2' }}; color:{{ $isActive ? '#166534' : '#991b1b' }};">
                                        {{ ucfirst($item->status ?? 'unknown') }}
                                    </span>
                                </td>
                                <td style="padding:12px;">
                                    <a href="{{ route('admin.users.profile', $item->id) }}" class="admin-user-btn admin-user-btn-sm">View User Profile</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" style="padding:16px; color:#6b7280;">No admins found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px;">
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
