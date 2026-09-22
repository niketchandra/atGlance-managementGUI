<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InstallationSeeder extends Seeder
{
    public function run(): void
    {
        Organization::query()->where('id', 200)->update([
            'name' => config('installer.organization_name'),
            'updated_at' => now(),
        ]);

        User::create([
            'rbac_id' => 100,
            'org_id' => 200,
            'name' => config('installer.admin_name'),
            'email' => config('installer.admin_email'),
            'password' => Hash::make(config('installer.admin_password')),
        ]);
    }
}