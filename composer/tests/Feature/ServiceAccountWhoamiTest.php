<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountWhoamiTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_service_account_whoami(): void
    {
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION, null, ['name' => 'org-agent']);
        $this->grant($account, $this->workspace, ['configs.read']);
        $this->grant($account, $this->otherWorkspace);
        $account->grants()->where('workspace_id', $this->otherWorkspace->id)->update(['status' => 'revoked']);
        [$plain, $token] = $this->accountKey($account, ['name' => 'fleet-a']);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])
            ->assertOk()
            ->assertJsonPath('identity_type', 'service_account')
            ->assertJsonPath('service_account.id', $account->id)
            ->assertJsonPath('service_account.name', 'org-agent')
            ->assertJsonPath('service_account.scope', 'organization')
            ->assertJsonPath('service_account.org_id', 200)
            ->assertJsonCount(1, 'workspaces')
            ->assertJsonPath('workspaces.0.id', $this->workspace->id)
            ->assertJsonPath('workspaces.0.name', 'production')
            ->assertJsonPath('workspaces.0.permissions', ['configs.read'])
            ->assertJsonPath('token.id', $token->id)
            ->assertJsonPath('token.name', 'fleet-a')
            ->assertJsonMissingPath('user');
    }

    public function test_user_whoami_has_identity_type(): void
    {
        $user = $this->makeUser('u@example.test');
        [$plain] = $this->userKey($user);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer ' . $plain])
            ->assertOk()
            ->assertJsonPath('identity_type', 'user')
            ->assertJsonPath('user.id', $user->id);
    }
}
