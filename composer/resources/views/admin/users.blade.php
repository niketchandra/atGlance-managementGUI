@extends('app')

@section('title', 'Admin Users - AtGlance')
@section('dashboard-content')
<div style="max-width:1100px;margin:40px auto;padding:0 24px;">
    <h1>Admin User Management</h1>
    @if (session('success'))<p style="color:#16806e;">{{ session('success') }}</p>@endif
    @if ($errors->any())<ul style="color:#9a3030;">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <form method="POST" action="{{ route('admin.users.store') }}" style="display:grid;gap:12px;max-width:520px;margin:24px 0;">
        @csrf
        <input name="name" placeholder="Full name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="password" name="password_confirmation" placeholder="Confirm password" required>
        <select name="role"><option value="user">User</option><option value="admin">Admin</option></select>
        <button type="submit">Create user</button>
    </form>
    <h2>Administrators</h2>
    @foreach ($adminUsers as $user)<p><a href="{{ route('admin.users.profile', $user) }}">{{ $user->name }}</a> &lt;{{ $user->email }}&gt;</p>@endforeach
    <h2>Users</h2>
    @foreach ($users as $user)<p><a href="{{ route('admin.users.profile', $user) }}">{{ $user->name }}</a> &lt;{{ $user->email }}&gt;</p>@endforeach
</div>
@endsection