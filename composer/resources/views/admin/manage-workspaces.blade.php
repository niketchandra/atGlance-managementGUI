@extends('app')

@section('title', 'Manage Workspaces - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 30px; color: var(--ag-text);">Manage Workspaces</h1>
        <a href="{{ route('dashboard') }}" style="text-decoration: none; color: var(--ag-text);">&larr; Back to Dashboard</a>
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

    @if($canCreateWorkspace)
        <div class="ag-card" style="padding: 18px; margin-bottom: 16px;">
            <h2 style="font-size: 18px; color: var(--ag-text); margin-bottom: 8px;">Create Workspace</h2>
            <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">You become the workspace admin and can then add users and admins.</p>
            <form method="POST" action="{{ route('admin.workspaces.store') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; align-items: end;">
                @csrf
                <div>
                    <label class="ag-label" for="workspaceName">Name</label>
                    <input class="ag-input" id="workspaceName" type="text" name="name" value="{{ old('name') }}" required maxlength="255" style="width: 100%;">
                </div>
                <div>
                    <label class="ag-label" for="workspaceDescription">Description</label>
                    <input class="ag-input" id="workspaceDescription" type="text" name="description" value="{{ old('description') }}" maxlength="512" style="width: 100%;">
                </div>
                <div>
                    <button class="ag-btn" type="submit"><i class="fas fa-plus"></i> Create Workspace</button>
                </div>
            </form>
        </div>
    @endif

    <div class="ag-card" style="padding: 18px;">
        <p style="font-size: 13px; color: var(--ag-muted); margin-bottom: 14px;">You can manage only the workspaces where you are assigned as workspace admin.</p>

        @if($workspaces->isEmpty())
            <div style="padding: 20px; background: var(--ag-surface); border-radius: 12px; text-align: center; color: var(--ag-muted); font-size: 13px;">
                No workspace is currently assigned to you as workspace admin.
            </div>
        @else
            <table class="ag-table" style="width: 100%;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--ag-line);">
                        <th style="text-align: left; padding: 12px;">Workspace ID</th>
                        <th style="text-align: left; padding: 12px;">Name</th>
                        <th style="text-align: left; padding: 12px;">Description</th>
                        <th style="text-align: left; padding: 12px;">Status</th>
                        <th style="text-align: left; padding: 12px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($workspaces as $workspace)
                        <tr style="border-bottom: 1px solid var(--ag-line);">
                            <td style="padding: 12px; font-size: 13px;">{{ $workspace->id }}</td>
                            <td style="padding: 12px; font-size: 13px; font-weight: 500;">{{ $workspace->name }}</td>
                            <td style="padding: 12px; font-size: 13px;">{{ Str::limit($workspace->description, 50) ?? '-' }}</td>
                            <td style="padding: 12px; font-size: 13px;">
                                <span style="background:{{ $workspace->status === 'active' ? '#e3faf3' : '#fdecec' }}; color:{{ $workspace->status === 'active' ? '#137a54' : '#b33b3b' }}; padding:4px 8px; border-radius:4px;">
                                    {{ ucfirst($workspace->status) }}
                                </span>
                            </td>
                            <td style="padding: 12px;">
                                <a href="{{ route('admin.workspaces.show', $workspace->id) }}" style="color: var(--ag-teal); text-decoration: none; font-weight: 600; font-size: 13px;">Manage Users &amp; Admins</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
