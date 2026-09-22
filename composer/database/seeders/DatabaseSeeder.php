<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Installation wizard uses InstallationSeeder with form-submitted org/admin data.
        // For development/testing, run AdminUserSeeder manually:
        // php artisan db:seed --class=AdminUserSeeder
    }
}
