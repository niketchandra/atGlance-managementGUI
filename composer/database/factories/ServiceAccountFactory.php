<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServiceAccount>
 */
class ServiceAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $org = Organization::factory()->create();
        $workspace = Workspace::factory()->create(['org_id' => $org->id]);

        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'scope' => 'workspace',
            'org_id' => $org->id,
            'workspace_id' => $workspace->id,
            'status' => 'active',
        ];
    }
}
