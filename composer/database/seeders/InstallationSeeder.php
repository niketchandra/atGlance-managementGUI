<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InstallationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organizationName = config('installer.organization_name', 'Default Organization');
        $adminName = config('installer.admin_name', 'Administrator');
        $adminEmail = config('installer.admin_email', 'admin@example.com');
        $adminPassword = config('installer.admin_password', 'password');

        $hashedPassword = Hash::make($adminPassword);

        Organization::updateOrCreate(
            ['id' => 200],
            [
                'name' => $organizationName,
                'description' => "Organization '{$organizationName}' created during installation",
                'status' => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => strtolower($adminEmail)],
            [
                'rbac_id' => 100,
                'org_id' => 200,
                'name' => $adminName,
                'password' => $hashedPassword,
                'password_hash' => $hashedPassword,
                'dob' => '1990-01-01',
            ]
        );

        $this->command->info('Installation seeder completed successfully!');
        $this->command->info("Organization: {$organizationName}");
        $this->command->info("Administrator: {$adminEmail}");
    }
}
