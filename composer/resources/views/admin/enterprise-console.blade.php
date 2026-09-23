@extends('app')

@section('title', 'Enterprise Console - AtGlance')

@section('dashboard-content')
<div style="padding:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:28px; color:#111827;">Enterprise Console</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration:none; color:#111827;">← Back to Dashboard</a>
    </div>

    <div style="padding:14px; border-radius:10px; background:#eef2ff; border:1px solid #c7d2fe; color:#3730a3; margin-bottom:16px;">
        Only Super admins can access this page.
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

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px;">
        <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Create New Workspace</h2>
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Create a workspace and optionally assign an existing admin.</p>

            <form method="POST" action="{{ route('enterprise.workspaces.store') }}">
                @csrf
                <div style="margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Workspace Name</label>
                        <input type="text" name="name" value="{{ old('name') }}" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Description</label>
                        <textarea name="description" rows="3" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px;">{{ old('description') }}</textarea>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Status</label>
                        <select name="status" required style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; background:white;">
                            <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; color:#4b5563; margin-bottom:6px;">Assign Workspace Admin (Optional)</label>
                        <select name="admin_user_id" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; background:white;">
                            <option value="">No admin assignment</option>
                            @foreach($adminCandidates as $admin)
                                <option value="{{ $admin->id }}" {{ (string) old('admin_user_id') === (string) $admin->id ? 'selected' : '' }}>{{ $admin->name }} ({{ $admin->email }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" style="background:#111827; color:white; border:none; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;">Create Workspace</button>
            </form>
        </div>

        <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Site Configuration</h2>
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">Open full site configuration settings from the enterprise console.</p>

            <div style="padding:14px; border:1px solid #dbeafe; background:#eff6ff; border-radius:8px; margin-bottom:14px; color:#1e3a8a; font-size:13px;">
                This section links to the complete platform-wide site settings.
            </div>

            <a href="{{ route('admin.settings', ['tab' => 'site']) }}" style="display:inline-block; background:#1f2937; color:white; text-decoration:none; border-radius:8px; padding:10px 14px; font-weight:600;">Open Site Configuration</a>
        </div>
    </div>

    <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <h2 style="font-size:18px; color:#111827; margin-bottom:8px;">Manage Workspaces</h2>
        <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">View and manage all workspaces within the organization.</p>

        @if($workspaces && count($workspaces) > 0)
            <div style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
                <table style="width:100%; border-collapse:collapse; min-width:600px;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5e7eb;">
                            <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Workspace ID</th>
                            <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Name</th>
                            <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Description</th>
                            <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Admins</th>
                            <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Users</th>
                            <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Status</th>
                            <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($workspaces as $workspace)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px; font-size:13px;">{{ $workspace->id }}</td>
                                <td style="padding:12px; font-size:13px; font-weight:500;">{{ $workspace->name }}</td>
                                <td style="padding:12px; font-size:13px; color:#6b7280;">{{ Str::limit($workspace->description, 30) ?? '-' }}</td>
                                <td style="padding:12px; font-size:13px;"><span style="background:#dbeafe; color:#1e3a8a; padding:4px 8px; border-radius:4px;">{{ $workspace->admins()->count() }}</span></td>
                                <td style="padding:12px; font-size:13px;"><span style="background:#dce7f5; color:#424c68; padding:4px 8px; border-radius:4px;">{{ $workspace->regularUsers()->count() }}</span></td>
                                <td style="padding:12px; font-size:13px;"><span style="background:{{ $workspace->status === 'active' ? '#dcfce7' : '#fee2e2' }}; color:{{ $workspace->status === 'active' ? '#166534' : '#991b1b' }}; padding:4px 8px; border-radius:4px;">{{ ucfirst($workspace->status) }}</span></td>
                                <td style="padding:12px;"><a href="{{ route('workspace.detail', $workspace->id) }}" style="color:#2563eb; text-decoration:none; font-weight:600; font-size:13px;">Manage</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="padding:20px; background:#f9fafb; border-radius:8px; text-align:center; color:#6b7280; font-size:13px;">
                No workspaces found. Create workspaces by managing workspace settings.
            </div>
        @endif
    </div>

</div>
@endsection
