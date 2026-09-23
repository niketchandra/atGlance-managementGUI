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
        $defaultOrg = Organization::find(200);

        if ($defaultOrg) {
            Workspace::firstOrCreate(
                ['name' => 'Default Workspace'],
                [
                    'org_id' => 200,
                    'description' => 'Default workspace for the organization',
                    'status' => 'active',
                ]
            );

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
