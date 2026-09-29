@extends('app')

@section('title', 'Dashboard - ' . $brandName)

@section('dashboard-content')
<div style="padding: 40px;">
    <style>
        .admin-action-btn {
            text-decoration: none;
            background: var(--ag-gradient);
            display: inline-flex;
            color: var(--ag-ink);
            padding: 10px 14px;
            border-radius: 999px;
            font-weight: 500;
            transition: background 0.2s ease;
        }

        .admin-action-btn:hover {
            background: linear-gradient(135deg, #5ff0c9 0%, #2fbaf2 100%) !important;
        }
    </style>

    <div class="ag-banner" style="padding: 32px; margin-bottom: 24px;">
        <h1 style="font-size: 30px; margin-bottom: 8px;">Dashboard</h1>
        <p style="opacity: 0.9;">Manage users, platform configuration, and global settings.</p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Users</div>
            <div class="ag-stat" style="margin-top: 8px;">{{ $totalUsers }}</div>
        </div>
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Systems</div>
            <div class="ag-stat" style="margin-top: 8px;">{{ $totalSystems }}</div>
        </div>
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Services</div>
            <div class="ag-stat" style="margin-top: 8px;">{{ $totalServices }}</div>
        </div>
        <div class="ag-card" style="padding: 18px;">
            <div style="font-size: 12px; color: var(--ag-muted); text-transform: uppercase;">Configuration Files</div>
            <div class="ag-stat" style="margin-top: 8px;">{{ $totalConfigFiles }}</div>
        </div>
    </div>

    <div style="display: flex; gap: 12px; margin-bottom: 24px;">
        <a href="{{ route('admin.users') }}" class="admin-action-btn">Manage Users</a>
        @if(auth()->check() && in_array((int) auth()->user()->rbac_id, [100, 101], true))
        <a href="{{ (int) auth()->user()->rbac_id === 100 ? route('enterprise.console') : route('admin.workspaces') }}" class="admin-action-btn">Manage Workspace</a>
        @endif
        @if(auth()->check() && (int) auth()->user()->rbac_id === 100)
        <a href="{{ route('admin.settings') }}" class="admin-action-btn">Site Setting</a>
        <a href="{{ route('enterprise.console') }}" class="admin-action-btn">Enterprise Console</a>
        @endif
    </div>
</div>
@endsection
