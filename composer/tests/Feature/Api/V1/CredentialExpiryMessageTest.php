<?php

namespace Tests\Feature\Api\V1;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Test Suite: Phase 5 - Credential Expiry Messages
 *
 * Verifies that /api/v1/credentials/expiry-notice creates messages in
 * temporary_credential_messages table and those messages are retrievable
 * via GET /api/v1/temporary-messages.
 */
class CredentialExpiryMessageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper method to get the plaintext token for API testing.
     */
    protected function getPlainToken(PatToken $token): string
    {
        return $token->token_encrypted ?? $token->token;
    }

    /**
     * Test: expiryNotice creates message for personal access token expiring soon
     */
    public function test_expiry_notice_creates_message_for_expiring_pat(): void
    {
        // Setup: Create user with PAT expiring in 5 days
        $user = User::factory()->create();
        $token = PatToken::factory()
            ->for($user, 'tokenable')
            ->create([
                'expires_at' => now()->addDays(5),
            ]);

        // Act: Call expiryNotice endpoint
        $response = $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($token)}",
        ]);

        // Assert: Response is successful
        $response->assertStatus(200);
        $response->assertJson(['queued' => true]);

        // Assert: Message was created in database
        $this->assertDatabaseHas('temporary_credential_messages', [
            'pat_token_id' => $token->id,
            'user_id' => $user->id,
            'service_account_id' => null,
            'status' => 'expiring',
            'days_left' => 5,
            'severity' => 'warn',
            'credential_type' => 'personal_access_token',
        ]);
    }

    /**
     * Test: expiryNotice creates message for expired PAT
     */
    public function test_expiry_notice_creates_message_for_expired_pat(): void
    {
        // Setup: Create user with expired PAT
        $user = User::factory()->create();
        $token = PatToken::factory()
            ->for($user, 'tokenable')
            ->create([
                'expires_at' => now()->subDay(),
            ]);

        // Act: Call expiryNotice endpoint
        $response = $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($token)}",
        ]);

        // Assert: Response is successful
        $response->assertStatus(200);

        // Assert: Message was created with expired status
        $this->assertDatabaseHas('temporary_credential_messages', [
            'pat_token_id' => $token->id,
            'status' => 'expired',
            'days_left' => 0,
            'severity' => 'error',
        ]);
    }

    /**
     * Test: expiryNotice creates message for service account key expiring soon
     */
    public function test_expiry_notice_creates_message_for_sa_key(): void
    {
        // Setup: Create service account with key expiring in 3 days
        $sa = ServiceAccount::factory()->create();
        $token = PatToken::factory()
            ->for($sa, 'tokenable')
            ->create([
                'expires_at' => now()->addDays(3),
            ]);

        // Act: Call expiryNotice endpoint
        $response = $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($token)}",
        ]);

        // Assert: Response is successful
        $response->assertStatus(200);

        // Assert: Message was created with service account ID
        $this->assertDatabaseHas('temporary_credential_messages', [
            'pat_token_id' => $token->id,
            'user_id' => null,
            'service_account_id' => $sa->id,
            'status' => 'expiring',
            'days_left' => 3,
            'severity' => 'error', // 3 days = error level
            'credential_type' => 'service_account',
        ]);
    }

    /**
     * Test: Messages are retrievable via GET /api/v1/temporary-messages
     */
    public function test_messages_endpoint_returns_created_messages(): void
    {
        // Setup: Create user with expiring PAT
        $user = User::factory()->create();
        $token = PatToken::factory()
            ->for($user, 'tokenable')
            ->create([
                'expires_at' => now()->addDays(7),
            ]);

        // Create message
        $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($token)}",
        ]);

        // Act: Fetch messages
        $response = $this->getJson('/api/v1/temporary-messages', [
            'Authorization' => "Bearer {$this->getPlainToken($token)}",
        ]);

        // Assert: Message is returned
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'messages');
        $response->assertJson([
            'count' => 1,
            'messages' => [
                [
                    'days_left' => 7,
                    'message' => "Your credential '{$token->name}' (…{$token->last_four}) expires in 7 days.",
                    'severity' => 'warn',
                    'credential_type' => 'personal_access_token',
                ]
            ]
        ]);
    }

    /**
     * Test: Message status is correctly set based on expiry state
     */
    public function test_message_status_reflects_credential_state(): void
    {
        $user = User::factory()->create();

        // Test 1: Active (not expiring)
        $activeToken = PatToken::factory()
            ->for($user, 'tokenable')
            ->create(['expires_at' => now()->addDays(30)]);

        $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($activeToken)}",
        ]);

        $this->assertDatabaseHas('temporary_credential_messages', [
            'pat_token_id' => $activeToken->id,
            'status' => 'active',
            'severity' => 'info',
        ]);

        // Test 2: Expiring soon
        $expiringToken = PatToken::factory()
            ->for($user, 'tokenable')
            ->create(['expires_at' => now()->addDays(2)]);

        $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($expiringToken)}",
        ]);

        $this->assertDatabaseHas('temporary_credential_messages', [
            'pat_token_id' => $expiringToken->id,
            'status' => 'expiring',
            'severity' => 'error',
        ]);

        // Test 3: Expired
        $expiredToken = PatToken::factory()
            ->for($user, 'tokenable')
            ->create(['expires_at' => now()->subDays(1)]);

        $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($expiredToken)}",
        ]);

        $this->assertDatabaseHas('temporary_credential_messages', [
            'pat_token_id' => $expiredToken->id,
            'status' => 'expired',
            'severity' => 'error',
        ]);
    }

    /**
     * Test: Multiple messages from different tokens are stored separately
     */
    public function test_multiple_messages_from_different_tokens(): void
    {
        $user = User::factory()->create();
        $token1 = PatToken::factory()->for($user, 'tokenable')->create(['expires_at' => now()->addDays(5)]);
        $token2 = PatToken::factory()->for($user, 'tokenable')->create(['expires_at' => now()->addDays(3)]);

        // Create messages for both tokens
        $this->postJson('/api/v1/credentials/expiry-notice', [], ['Authorization' => "Bearer {$this->getPlainToken($token1)}"]);
        $this->postJson('/api/v1/credentials/expiry-notice', [], ['Authorization' => "Bearer {$this->getPlainToken($token2)}"]);

        // Verify both messages exist
        $this->assertDatabaseHas('temporary_credential_messages', ['pat_token_id' => $token1->id]);
        $this->assertDatabaseHas('temporary_credential_messages', ['pat_token_id' => $token2->id]);

        // Fetch via endpoint
        $response = $this->getJson('/api/v1/temporary-messages', [
            'Authorization' => "Bearer {$this->getPlainToken($token1)}",
        ]);

        $response->assertJsonCount(2, 'messages');
    }

    /**
     * Test: Messages expire based on TTL
     */
    public function test_expired_messages_not_returned(): void
    {
        $user = User::factory()->create();
        $token = PatToken::factory()
            ->for($user, 'tokenable')
            ->create(['expires_at' => now()->addDays(5)]);

        // Create message
        $this->postJson('/api/v1/credentials/expiry-notice', [], [
            'Authorization' => "Bearer {$this->getPlainToken($token)}",
        ]);

        // Manually expire the message
        \DB::table('temporary_credential_messages')
            ->where('pat_token_id', $token->id)
            ->update(['expires_at' => now()->subMinutes(1)]);

        // Fetch messages
        $response = $this->getJson('/api/v1/temporary-messages', [
            'Authorization' => "Bearer {$this->getPlainToken($token)}",
        ]);

        // Should not return expired message
        $response->assertJson(['count' => 0, 'messages' => []]);
    }
}
