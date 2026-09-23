<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get default organization (ID 200)
        $defaultOrg = Organization::find(200);
        
        if ($defaultOrg) {
            // Create default workspace
            Workspace::firstOrCreate(
                ['name' => 'Default Workspace'],
                [
                    'org_id' => 200,
                    'description' => 'Default workspace for the organization',
                    'status' => 'active',
                ]
            );

            // Create sample workspaces if needed
            Workspace::firstOrCreate(
                ['name' => 'Development'],
                [
                    'org_id' => 200,
                    'description' => 'Development environment workspace',
                    'status' => 'active',
                ]
            );

            Workspace::firstOrCreate(
                ['name' => 'Production'],
                [
                    'org_id' => 200,
                    'description' => 'Production environment workspace',
                    'status' => 'active',
                ]
            );
        }
    }
}
