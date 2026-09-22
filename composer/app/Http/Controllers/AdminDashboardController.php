<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationFile;
use App\Models\Organization;
use App\Models\Service;
use App\Models\SystemRegister;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'totalUsers' => User::count(),
            'totalSystems' => SystemRegister::count(),
            'totalServices' => Service::count(),
            'totalConfigFiles' => ConfigurationFile::count(),
        ]);
    }

    public function usersIndex(): View
    {
        return view('admin.users', [
            'users' => User::whereNotIn('rbac_id', [100, 101])->latest()->get(),
            'adminUsers' => User::where('rbac_id', 101)->latest()->get(),
        ]);
    }

    public function createUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'rbac_id' => $validated['role'] === 'admin' ? 101 : 102,
            'org_id' => Organization::query()->min('id') ?? 200,
        ]);

        return redirect()->route('admin.users')->with('success', 'User registered successfully.');
    }

    public function userProfile(User $user): View
    {
        return view('admin.user-profile', compact('user'));
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'rbac_id' => $validated['role'] === 'admin' ? 101 : 102,
        ]);

        return redirect()->route('admin.users.profile', $user)->with('success', 'User profile updated successfully.');
    }
}