@extends('app')

@section('title', 'Manage Workspaces - AtGlance')

@section('dashboard-content')
<div style="padding:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="font-size:28px; color:#111827;">Manage Workspaces</h1>
        <a href="{{ route('admin.dashboard') }}" style="text-decoration:none; color:#111827;">&larr; Back to Dashboard</a>
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

    <div style="background:white; border:1px solid #e5e7eb; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.06);">
        <p style="font-size:13px; color:#6b7280; margin-bottom:14px;">You can manage only the workspaces where you are assigned as workspace admin.</p>

        @if($workspaces->isEmpty())
            <div style="padding:20px; background:#f9fafb; border-radius:8px; text-align:center; color:#6b7280; font-size:13px;">
                No workspace is currently assigned to you as workspace admin.
            </div>
        @else
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid #e5e7eb;">
                        <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Workspace ID</th>
                        <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Name</th>
                        <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Description</th>
                        <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Status</th>
                        <th style="text-align:left; padding:12px; font-weight:600; color:#4b5563; font-size:13px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($workspaces as $workspace)
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px; font-size:13px;">{{ $workspace->id }}</td>
                            <td style="padding:12px; font-size:13px; font-weight:500;">{{ $workspace->name }}</td>
                            <td style="padding:12px; font-size:13px; color:#6b7280;">{{ Str::limit($workspace->description, 50) ?? '-' }}</td>
                            <td style="padding:12px; font-size:13px;">
                                <span style="background:{{ $workspace->status === 'active' ? '#dcfce7' : '#fee2e2' }}; color:{{ $workspace->status === 'active' ? '#166534' : '#991b1b' }}; padding:4px 8px; border-radius:4px;">
                                    {{ ucfirst($workspace->status) }}
                                </span>
                            </td>
                            <td style="padding:12px;">
                                <a href="{{ route('admin.workspaces.show', $workspace->id) }}" style="color:#2563eb; text-decoration:none; font-weight:600; font-size:13px;">Manage Users</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
