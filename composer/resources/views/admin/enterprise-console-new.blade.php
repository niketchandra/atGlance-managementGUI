@extends('app')

@section('title', 'Enterprise Console - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">Enterprise Console</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration: none; color: var(--ag-text);">← Back to Dashboard</a>
    </div>

    <div style="padding: 14px; border-radius: 16px; background: #e4f6ff; border: 1px solid #c7d2fe; color: #3730a3; margin-bottom: 16px;">
        Only users with RBAC ID 100 (Super Admin) can access this page.
    </div>

    @if(session('success'))
        <div style="padding: 12px; border-radius: 12px; background: var(--ag-success-soft); color: var(--ag-success); margin-bottom: 16px;">
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

    <div class="ag-card" style="padding: 18px; margin-bottom: 18px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Create New Admin</h2>
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">Create an admin account with role 101 that can be assigned to workspaces.</p>

        <form method="POST" action="{{ route('enterprise.admins.store') }}">
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
                    <input class="ag-input" type="text" name="first_name" value="{{ old('first_name') }}" style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label">Last Name</label>
                    <input class="ag-input" type="text" name="last_name" value="{{ old('last_name') }}" style="width: 100%;">
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

            <button class="ag-btn" type="submit">Create Admin</button>
        </form>
    </div>

    <div class="ag-card" style="padding: 18px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Manage Workspaces</h2>
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">View and manage all workspaces, add admins, manage users, and control workspace status.</p>

        @if($workspaces->isEmpty())
            <div style="padding: 16px; background: var(--ag-surface); border-radius: 12px; color: var(--ag-muted); text-align: center;">
                No workspaces found. Workspaces should be created during application installation.
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="ag-table" style="width: 100%;">
                    <thead>
                        <tr style="background: var(--ag-surface); border-bottom: 1px solid var(--ag-line);">
                            <th style="text-align: left; padding: 12px;">Workspace ID</th>
                            <th style="text-align: left; padding: 12px;">Name</th>
                            <th style="text-align: left; padding: 12px;">Description</th>
                            <th style="text-align: left; padding: 12px;">Admins</th>
                            <th style="text-align: left; padding: 12px;">Users</th>
                            <th style="text-align: left; padding: 12px;">Status</th>
                            <th style="text-align: left; padding: 12px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($workspaces as $workspace)
                            <tr style="border-bottom: 1px solid var(--ag-line);">
                                <td style="padding: 12px;">{{ $workspace->id }}</td>
                                <td style="padding: 12px;">{{ $workspace->name }}</td>
                                <td style="padding: 12px; font-size: 12px;">{{ Str::limit($workspace->description, 30) ?? 'N/A' }}</td>
                                <td style="padding: 12px;">{{ $workspace->admins()->count() }}</td>
                                <td style="padding: 12px;">{{ $workspace->regularUsers()->count() }}</td>
                                <td style="padding: 12px;">
                                    @php $isActive = strtolower((string) $workspace->status) === 'active'; @endphp
                                    <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:{{ $isActive ? '#e3faf3' : '#fdecec' }}; color:{{ $isActive ? '#137a54' : '#b33b3b' }};">
                                        {{ ucfirst($workspace->status) }}
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <a class="ag-btn" href="{{ route('workspace.detail', $workspace->id) }}" style="display: inline-block; text-decoration: none;">Manage</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
