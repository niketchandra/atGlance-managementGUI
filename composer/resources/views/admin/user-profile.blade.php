@extends('app')

@section('title', 'Admin User Profile - AtGlance')
@section('dashboard-content')
<div style="max-width:700px;margin:40px auto;padding:0 24px;">
    <h1>Edit User</h1>
    @if (session('success'))<p style="color:#16806e;">{{ session('success') }}</p>@endif
    <form method="POST" action="{{ route('admin.users.update', $user) }}" style="display:grid;gap:12px;margin-top:24px;">
        @csrf
        @method('PUT')
        <input name="name" value="{{ old('name', $user->name) }}" required>
        <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
        <select name="role">
            <option value="user" @selected($user->rbac_id === 102)>User</option>
            <option value="admin" @selected(in_array($user->rbac_id, [100, 101]))>Admin</option>
        </select>
        <button type="submit">Save changes</button>
    </form>
</div>
@endsection