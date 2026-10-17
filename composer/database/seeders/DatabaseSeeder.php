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
        // Seed installation defaults (organization, admin user)
        $this->call(InstallationSeeder::class);

        // Seed default workspaces
        $this->call(WorkspaceSeeder::class);
    }
}
