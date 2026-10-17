<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountRegistrationTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'system_name' => 'db-01',
            'os_type' => 'linux',
            'ip_address' => '10.0.0.9',
            'workspace_id' => $this->workspace->id,
            'system_key' => str_repeat('a', 40),
        ], $overrides);
    }

    private function headers(string $userKey, ?string $accountKey): array
    {
        return array_filter([
            'Authorization' => 'Bearer ' . $userKey,
            'X-Atglance-Service-Account-Key' => $accountKey,
        ]);
    }

    public function test_both_keys_register_a_system_owned_by_the_account(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey, $userToken] = $this->userKey($user);
        $account = $this->makeAccount();
        [$accountKey] = $this->accountKey($account);

        $response = $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $accountKey))->assertCreated();

        $system = SystemRegister::findOrFail($response->json('system.id'));
        $this->assertSame($account->id, (int) $system->service_account_id);
        $this->assertSame($user->id, (int) $system->user_id);
        $this->assertSame($userToken->id, (int) $system->pat_token_id);
        $response->assertJsonPath('system.service_account_id', $account->id);
        $this->assertTrue(ActivityLog::where('event', 'system.registered')->where('description', 'like', '%' . $account->name . '%')->exists());
    }

    public function test_user_key_alone_works_when_setting_off_and_fails_when_on(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey] = $this->userKey($user);

        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, null))->assertCreated();

        ServiceAccountSettings::save(['sa_require_for_registration' => true]);
        $this->postJson('/api/v1/systems', $this->body(['system_name' => 'db-02']), $this->headers($userKey, null))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Registration needs a user key and a service account key');

        $this->postJson('/api/system-register', ['system_name' => 'db-03', 'os_type' => 'linux', 'ip_address' => '10.0.0.3'], ['Authorization' => 'Bearer ' . $userKey])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Registration needs a user key and a service account key');
    }

    public function test_account_key_alone_is_401(): void
    {
        [$accountKey] = $this->accountKey($this->makeAccount());

        $this->postJson('/api/v1/systems', $this->body(), ['Authorization' => 'Bearer ' . $accountKey])->assertStatus(401);
    }

    public function test_account_without_grant_or_permission_is_refused(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey] = $this->userKey($user);

        $elsewhere = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->otherWorkspace);
        [$elsewhereKey] = $this->accountKey($elsewhere);
        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $elsewhereKey))
            ->assertStatus(403)->assertJsonPath('message', 'Service account lacks systems.register in this workspace');

        $readOnly = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($readOnly, $this->workspace, ['configs.read']);
        [$readOnlyKey] = $this->accountKey($readOnly);
        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $readOnlyKey))->assertStatus(403);
    }

    public function test_account_of_other_organization_or_bad_key_is_refused(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$userKey] = $this->userKey($user);

        $theirs = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->foreignWorkspace);
        [$theirKey] = $this->accountKey($theirs);
        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $theirKey))->assertStatus(401);

        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, 'atgsa-' . str_repeat('x', 32)))->assertStatus(401)
            ->assertJsonPath('message', 'Invalid service account key');

        $this->postJson('/api/v1/systems', $this->body(), $this->headers($userKey, $userKey))->assertStatus(401);
        $this->assertSame(0, SystemRegister::count());
    }
}
