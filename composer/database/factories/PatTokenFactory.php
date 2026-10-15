<?php

namespace Database\Factories;

use App\Models\PatToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PatToken>
 */
class PatTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate a plain token in the format expected by the system
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomPart = '';
        $randomLength = 25 - strlen('atgla-');

        for ($i = 0; $i < $randomLength; $i++) {
            $randomPart .= $characters[random_int(0, strlen($characters) - 1)];
        }

        $plainToken = 'atgla-' . $randomPart;

        return [
            'tokenable_type' => User::class,
            'tokenable_id' => User::factory(),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            // For testing purposes, store the hashed token in the database
            'token' => hash('sha256', $plainToken),
            'token_encrypted' => $plainToken, // Store plaintext in token_encrypted for testing
            'last_four' => substr($plainToken, -4),
            'status' => 'active',
            'expires_at' => now()->addDays(90),
        ];
    }

    /**
     * Configure the factory to make the plain token accessible in tests.
     */
    public function configure()
    {
        return $this->afterCreating(function (PatToken $token) {
            // Store a reference to the plain token that can be accessed by tests
            // We'll store it in the token_encrypted field if it's not already there
            // For now, we've already set it in the definition
        });
    }

    /**
     * Indicate that the token should be expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the token should expire soon (5 days).
     */
    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays(5),
        ]);
    }
}
