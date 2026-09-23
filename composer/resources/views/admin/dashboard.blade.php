@extends('app')

@section('title', 'Dashboard - AtGlance')

@section('dashboard-content')
<div style="padding: 40px;">
    <style>
        .admin-action-btn {
            text-decoration: none;
            color: white;
            padding: 10px 14px;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.2s ease;
        }

        .admin-action-btn:hover {
            background: #555555 !important;
        }
    </style>

    <div style="background: linear-gradient(135deg, #111111 0%, #111827 100%); color: white; padding: 32px; border-radius: 10px; margin-bottom: 24px;">
        <h1 style="font-size: 28px; margin-bottom: 8px;">Dashboard</h1>
        <p style="opacity: 0.9;">Manage users, platform configuration, and global settings.</p>
    </div>

    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px;">
        <div style="background:white; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #111111;">
            <div style="font-size:12px; color:#6b7280; text-transform:uppercase;">Users</div>
            <div style="font-size:28px; font-weight:700; color:#111827;">{{ $totalUsers }}</div>
        </div>
        <div style="background:white; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #111111;;">
            <div style="font-size:12px; color:#6b7280; text-transform:uppercase;">Systems</div>
            <div style="font-size:28px; font-weight:700; color:#111827;">{{ $totalSystems }}</div>
        </div>
        <div style="background:white; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #111111;;">
            <div style="font-size:12px; color:#6b7280; text-transform:uppercase;">Services</div>
            <div style="font-size:28px; font-weight:700; color:#111827;">{{ $totalServices }}</div>
        </div>
        <div style="background:white; border-radius:10px; padding:18px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #111111;;">
            <div style="font-size:12px; color:#6b7280; text-transform:uppercase;">Configuration Files</div>
            <div style="font-size:28px; font-weight:700; color:#111827;">{{ $totalConfigFiles }}</div>
        </div>
    </div>

    <div style="display:flex; gap:12px; margin-bottom:24px;">
        <a href="{{ route('admin.users') }}" class="admin-action-btn" style="background:#111111;">Manage Users</a>
        @if(auth()->check() && in_array((int) auth()->user()->rbac_id, [100, 101], true))
        <a href="{{ (int) auth()->user()->rbac_id === 100 ? route('enterprise.console') : route('admin.workspaces') }}" class="admin-action-btn" style="background:#374151;">Manage Workspace</a>
        @endif
        @if(auth()->check() && (int) auth()->user()->rbac_id === 100)
        <a href="{{ route('admin.settings') }}" class="admin-action-btn" style="background:#111827;">Site Setting</a>
        <a href="{{ route('enterprise.console') }}" class="admin-action-btn" style="background:#1f2937;">Enterprise Console</a>
        @endif
    </div>
</div>
@endsection
