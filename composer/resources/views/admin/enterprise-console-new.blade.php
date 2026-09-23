@extends('app')

@section('title', 'Enterprise Console - AtGlance')

@section('dashboard-content')
<div style="padding:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:28px; color:#111827;">Enterprise Console</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration:none; color:#111827;">← Back to Dashboard</a>
    </div>

    <div style="padding:14px; border-radius:10px; background:#eef2ff; border:1px solid #c7d2fe; color:#3730a3; margin-bottom:16px;">
        Only users with RBAC ID 100 (Super Admin) can access this page.
    </div>

    @if(session('success'))
        <div style="padding:12px; border-radius:8px; background:#dcfce7; color:#166534; margin-bottom:16px;">
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

    <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06); margin-bottom:18px;">
        <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Create New Admin</h2>
        <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Create an admin account with role 101 that can be assigned to workspaces.</p>

        <form method="POST" action="{{ route('enterprise.admins.store') }}">
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
                    <input type="text" name="first_name" value="{{ old('first_name') }}" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Last Name</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
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

            <button type="submit" style="background:#111827; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Create Admin</button>
        </form>
    </div>

    <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Manage Workspaces</h2>
        <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">View and manage all workspaces, add admins, manage users, and control workspace status.</p>

        @if($workspaces->isEmpty())
            <div style="padding:16px; background:#f3f4f6; border-radius:8px; color:#6b7280; text-align:center;">
                No workspaces found. Workspaces should be created during application installation.
            </div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb;">
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Workspace ID</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Name</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Description</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Admins</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Users</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Status</th>
                            <th style="text-align:left; padding:12px; font-size:12px; color:#6b7280;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($workspaces as $workspace)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px;">{{ $workspace->id }}</td>
                                <td style="padding:12px;">{{ $workspace->name }}</td>
                                <td style="padding:12px; font-size:12px;">{{ Str::limit($workspace->description, 30) ?? 'N/A' }}</td>
                                <td style="padding:12px;">{{ $workspace->admins()->count() }}</td>
                                <td style="padding:12px;">{{ $workspace->regularUsers()->count() }}</td>
                                <td style="padding:12px;">
                                    @php $isActive = strtolower((string) $workspace->status) === 'active'; @endphp
                                    <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:{{ $isActive ? '#dcfce7' : '#fee2e2' }}; color:{{ $isActive ? '#166534' : '#991b1b' }};">
                                        {{ ucfirst($workspace->status) }}
                                    </span>
                                </td>
                                <td style="padding:12px;">
                                    <a href="{{ route('workspace.detail', $workspace->id) }}" style="display:inline-block; background:#111827; color:white; text-decoration:none; border-radius:8px; padding:6px 10px; font-size:12px; font-weight:600;">Manage</a>
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
