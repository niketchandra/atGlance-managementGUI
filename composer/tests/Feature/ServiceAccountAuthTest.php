<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountAuthTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_account_key_works_on_v1_whoami(): void
    {
        [$plain] = $this->accountKey($this->makeAccount());

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertOk();
    }

    public function test_expired_revoked_and_disabled_are_401(): void
    {
        $account = $this->makeAccount();
        [$expired] = $this->accountKey($account, ['expires_at' => now()->subMinute()]);
        [$revoked] = $this->accountKey($account, ['status' => 'revoked', 'revoked_at' => now()]);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $expired])->assertStatus(401)->assertJsonPath('message', 'Key has expired');
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $revoked])->assertStatus(401)->assertJsonPath('message', 'Key is revoked');

        $disabled = $this->makeAccount();
        [$plain] = $this->accountKey($disabled);
        $disabled->update(['status' => 'disabled']);
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401)->assertJsonPath('message', 'Identity is disabled');
    }

    public function test_prefix_must_match_owner_type(): void
    {
        $user = $this->makeUser('u@example.test');
        [$userPlain] = $this->userKey($user);
        $relabelled = 'atgsa-' . substr($userPlain, 6);
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $relabelled])->assertStatus(401);

        // An atgsa- row stored with a User owner is rejected.
        $account = $this->makeAccount();
        [$plain, $token] = $this->accountKey($account);
        $token->forceFill(['tokenable_type' => User::class, 'tokenable_id' => $user->id])->save();
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401)->assertJsonPath('message', 'Invalid key');
    }

    public function test_account_key_refused_on_legacy_and_mcp_routes(): void
    {
        [$plain] = $this->accountKey($this->makeAccount());
        $headers = ['Authorization' => 'Bearer ' . $plain];

        $this->getJson('/api/config-files', $headers)->assertStatus(401);
        $this->postJson('/api/system-register', [], $headers)->assertStatus(401);
        $this->getJson('/api/mcp/me', $headers)->assertStatus(401);
        $this->getJson('/api/services', $headers)->assertStatus(401)
            ->assertJsonPath('message', 'Service account keys work only with the atglance CLI API (/api/v1)');
    }

    public function test_user_keys_still_work_everywhere(): void
    {
        $user = $this->makeUser('u@example.test');
        [$plain] = $this->userKey($user);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertOk();
        $this->getJson('/api/config-files', ['Authorization' => 'Bearer ' . $plain])->assertOk();
    }

    public function test_inactive_user_key_is_refused(): void
    {
        $user = $this->makeUser('u@example.test', 102, 200, 'inactive');
        [$plain] = $this->userKey($user);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertStatus(401)->assertJsonPath('message', 'Identity is disabled');
    }

    public function test_unknown_prefix_is_403_as_before(): void
    {
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer nope-123'])->assertStatus(403);
    }
}
