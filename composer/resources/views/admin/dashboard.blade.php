@extends('app')

@section('title', 'Admin Dashboard - AtGlance')
@section('dashboard-content')
<div style="max-width: 1100px; margin: 40px auto; padding: 0 24px;">
    <h1>Admin Dashboard</h1>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:24px;">
        @foreach(['Users' => $totalUsers, 'Systems' => $totalSystems, 'Services' => $totalServices, 'Configuration files' => $totalConfigFiles] as $label => $value)
            <div style="padding:20px;border:1px solid #d9e4df;border-radius:8px;background:#fff;"><strong>{{ $label }}</strong><div style="font-size:28px;margin-top:8px;">{{ $value }}</div></div>
        @endforeach
    </div>
    <p style="margin-top:24px;"><a href="{{ route('admin.users') }}">Manage users</a></p>
</div>
@endsection