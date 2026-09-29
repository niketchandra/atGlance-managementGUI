@extends('app')

@section('title', 'Enterprise Console - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">Enterprise Console</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration: none; color: var(--ag-text);">← Back to Dashboard</a>
    </div>

    <div style="padding: 14px; border-radius: 16px; background: #e4f6ff; border: 1px solid #c7d2fe; color: #3730a3; margin-bottom: 16px;">
        Only Super admins can access this page.
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

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px;">
        <div class="ag-card" style="padding: 18px;">
            <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Create New Workspace</h2>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">Create a workspace and optionally assign an existing admin.</p>

            <form method="POST" action="{{ route('enterprise.workspaces.store') }}">
                @csrf
                <div style="margin-bottom: 12px;">
                    <div>
                        <label class="ag-label">Workspace Name</label>
                        <input class="ag-input" type="text" name="name" value="{{ old('name') }}" required style="width: 100%;">
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <div>
                        <label class="ag-label">Description</label>
                        <textarea class="ag-textarea" name="description" rows="3" style="width: 100%;">{{ old('description') }}</textarea>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label class="ag-label">Status</label>
                        <select class="ag-select" name="status" required style="width: 100%;">
                            <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label class="ag-label">Assign Workspace Admin (Optional)</label>
                        <select class="ag-select" name="admin_user_id" style="width: 100%;">
                            <option value="">No admin assignment</option>
                            @foreach($adminCandidates as $admin)
                                <option value="{{ $admin->id }}" {{ (string) old('admin_user_id') === (string) $admin->id ? 'selected' : '' }}>{{ $admin->name }} ({{ $admin->email }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button class="ag-btn" type="submit">Create Workspace</button>
            </form>
        </div>

        <div class="ag-card" style="padding: 18px;">
            <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Site Configuration</h2>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">Open full site configuration settings from the enterprise console.</p>

            <div style="padding: 14px; border: 1px solid #dbeafe; background: #e4f6ff; border-radius: 12px; margin-bottom: 14px; color: #1f7fb8; font-size: 13px;">
                This section links to the complete platform-wide site settings.
            </div>

            <a class="ag-btn" href="{{ route('admin.settings', ['tab' => 'site']) }}" style="display: inline-block; text-decoration: none;">Open Site Configuration</a>
        </div>
    </div>

    <div class="ag-card" style="padding: 18px;">
        <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Manage Workspaces</h2>
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">View and manage all workspaces within the organization.</p>

        @if($workspaces && count($workspaces) > 0)
            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table class="ag-table" style="width: 100%; min-width: 600px;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--ag-line);">
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
                                <td style="padding: 12px; font-size: 13px;">{{ $workspace->id }}</td>
                                <td style="padding: 12px; font-size: 13px; font-weight: 500;">{{ $workspace->name }}</td>
                                <td style="padding: 12px; font-size: 13px;">{{ Str::limit($workspace->description, 30) ?? '-' }}</td>
                                <td style="padding: 12px; font-size: 13px;"><span style="background: #e4f6ff; color: #1f7fb8; padding: 4px 8px; border-radius: 4px;">{{ $workspace->admins()->count() }}</span></td>
                                <td style="padding: 12px; font-size: 13px;"><span style="background: #dce7f5; color: #424c68; padding: 4px 8px; border-radius: 4px;">{{ $workspace->regularUsers()->count() }}</span></td>
                                <td style="padding: 12px; font-size: 13px;"><span style="background:{{ $workspace->status === 'active' ? '#e3faf3' : '#fdecec' }}; color:{{ $workspace->status === 'active' ? '#137a54' : '#b33b3b' }}; padding:4px 8px; border-radius:4px;">{{ ucfirst($workspace->status) }}</span></td>
                                <td style="padding: 12px;"><a href="{{ route('workspace.detail', $workspace->id) }}" style="color: var(--ag-teal); text-decoration: none; font-weight: 600; font-size: 13px;">Manage</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="padding: 20px; background: var(--ag-surface); border-radius: 12px; text-align: center; color: var(--ag-muted); font-size: 13px;">
                No workspaces found. Create workspaces by managing workspace settings.
            </div>
        @endif
    </div>

</div>
@endsection
