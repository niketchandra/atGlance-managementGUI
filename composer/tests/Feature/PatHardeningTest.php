<?php

namespace Tests\Feature;

use App\Models\PatToken;
use App\Models\User;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class PatHardeningTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        $this->withoutMiddleware([\App\Http\Middleware\EnsureApplicationInstalled::class, \App\Http\Middleware\EnsureProfileSetupComplete::class]);
        $this->activateLicence();
    }

    /**
     * createApiKey and PatTokenController::store refuse without an active licence.
     * Set whatever App\Support\License::isActive() reads (see LicenseTest for the pattern).
     */
    private function activateLicence(): void
    {
        \App\Models\AdminSetting::putValue('license', 'license_status', \App\Services\LicenseClient::VERIFIED_STATUS);
        \App\Models\AdminSetting::putValue('license', 'license_key', 'test-key');
    }

    public function test_console_key_is_hash_only_with_default_expiry(): void
    {
        $user = $this->makeUser('u@example.test');

        $response = $this->actingAs($user)->postJson(route('settings.api-keys.create'), ['name' => 'laptop'])->assertOk();
        $key = PatToken::findOrFail($response->json('key_id'));

        $this->assertNull($key->token_encrypted);
        $this->assertSame(hash('sha256', $response->json('token')), $key->token);
        $this->assertSame(substr($response->json('token'), -4), $key->last_four);
        $this->assertTrue($key->expires_at->between(now()->addDays(89), now()->addDays(91)));
    }

    public function test_expiry_over_maximum_is_refused(): void
    {
        $user = $this->makeUser('u@example.test');

        $this->actingAs($user)->postJson(route('settings.api-keys.create'), ['name' => 'x', 'expiration_date' => now()->addDays(400)->toDateString()])
            ->assertStatus(422);
    }

    public function test_limit_and_same_name_replace(): void
    {
        ServiceAccountSettings::save(['pat_user_quota' => 2]);
        $user = $this->makeUser('u@example.test');
        $hostA = $this->namedKey($user, 'host-a');
        $this->namedKey($user, 'host-b');
        $this->actingAs($user);

        $this->postJson(route('settings.api-keys.create'), ['name' => 'host-c'])->assertStatus(409);

        $this->postJson(route('settings.api-keys.create'), ['name' => 'host-a'])->assertOk();
        $old = PatToken::where('token', hash('sha256', $hostA))->first();
        $this->assertSame('revoked', $old->status);
        $this->assertSame('replaced', $old->revoke_reason);
        $this->assertSame(2, PatToken::where('tokenable_id', $user->id)->where('tokenable_type', User::class)->where('status', 'active')->count());
    }

    public function test_expired_and_revoked_keys_do_not_count(): void
    {
        ServiceAccountSettings::save(['pat_user_quota' => 1]);
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);
        $key->update(['expires_at' => now()->subDay()]);

        $this->actingAs($user)->postJson(route('settings.api-keys.create'), ['name' => 'new'])->assertOk();
    }

    public function test_view_endpoint_is_gone(): void
    {
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);

        $this->actingAs($user)->postJson(route('settings.api-keys.view'), ['key_id' => $key->id, 'pin' => '1234'])
            ->assertStatus(410)->assertJsonPath('message', 'API keys are shown only when created');
    }

    public function test_api_index_hides_hashes_and_store_uses_the_same_rules(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->userKey($user);
        $headers = $this->sessionHeaders($user);

        $this->getJson('/api/auth/pat-tokens', $headers)
            ->assertOk()->assertJsonMissingPath('tokens.0.token_hash')->assertJsonPath('tokens.0.status', 'active');

        $created = $this->postJson('/api/auth/pat-tokens', ['name' => 'cli-host'], $headers)->assertCreated();
        $this->assertNull(PatToken::findOrFail($created->json('token.id'))->token_encrypted);
    }

    public function test_console_revoke_records_actor_and_reason(): void
    {
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);

        $this->actingAs($user)->postJson(route('settings.api-keys.revoke'), ['key_id' => $key->id, 'pin' => '1234'])->assertOk();
        $key->refresh();
        $this->assertSame('revoked', $key->status);
        $this->assertSame('revoked_by_user', $key->revoke_reason);
        $this->assertSame($user->id, (int) $key->revoked_by_user_id);
    }

    public function test_migration_clears_stored_plaintext_and_old_key_still_works(): void
    {
        $user = $this->makeUser('u@example.test');
        [$plain, $key] = $this->userKey($user);
        $key->forceFill(['token_encrypted' => Crypt::encryptString($plain)])->save();

        $migration = require database_path('migrations/2026_10_14_000000_version_2_1_2_schema.php');
        $migration->up();

        $this->assertNull($key->fresh()->token_encrypted);
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])->assertOk();
    }

    private function namedKey(User $user, string $name): string
    {
        [$plain, $token] = $this->userKey($user);
        $token->update(['name' => $name]);

        return $plain;
    }

    /**
     * /api/auth/pat-tokens uses auth.session (the session token from /api/auth/login).
     *
     * @return array<string, string>
     */
    private function sessionHeaders(User $user): array
    {
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();

        return ['Authorization' => 'Bearer ' . $login->json('access_token')];
    }
}
