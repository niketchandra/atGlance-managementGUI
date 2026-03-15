<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default super admin user with ID 1010
        $hashedPassword = Hash::make('Atglance@123');

        User::updateOrCreate([
            'email' => 'superadmin@admin.com',
        ], [
            'id' => 1010,
            'rbac_id' => 100, // super_admin role
            'org_id' => 200, // default organization
            'name' => 'superadmin',
            'password' => $hashedPassword,
            'password_hash' => $hashedPassword,
            'dob' => '1990-01-01',
        ]);

        $this->command->info('Default super admin user created successfully!');
        $this->command->info('Email: superadmin@admin.com');
        $this->command->info('Password: Atglance@123');
    }
}
