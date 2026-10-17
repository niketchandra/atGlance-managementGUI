<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ServiceAccount;
use App\Services\ServiceAccountException;
use App\Services\ServiceAccounts;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountServiceTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    private ServiceAccounts $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        $this->accounts = app(ServiceAccounts::class);
    }

    public function test_create_workspace_account_adds_its_fixed_grant(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $account = $this->accounts->create($admin, [
            'name' => 'prod-backup',
            'scope' => ServiceAccount::SCOPE_WORKSPACE,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->assertSame($admin->id, (int) $account->owner_user_id);
        $this->assertSame($admin->id, (int) $account->created_by_user_id);
        $this->assertTrue($account->can('configs.write', $this->workspace->id));
        $this->assertSame(1, $account->grants()->count());
        $this->assertTrue(ActivityLog::where('event', 'service_account.created')->exists());
    }

    public function test_create_rejects_workspace_of_other_organization(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $this->expectException(ServiceAccountException::class);
        $this->accounts->create($admin, ['name' => 'x', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->foreignWorkspace->id]);
    }

    public function test_create_rejects_duplicate_name_in_scope(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $this->accounts->create($admin, ['name' => 'dup', 'scope' => ServiceAccount::SCOPE_ORGANIZATION]);

        $this->expectException(ServiceAccountException::class);
        $this->accounts->create($admin, ['name' => 'dup', 'scope' => ServiceAccount::SCOPE_ORGANIZATION]);
    }

    public function test_workspace_quota(): void
    {
        ServiceAccountSettings::save(['sa_workspace_quota' => 1]);
        $admin = $this->makeUser('admin@example.test', 101);
        $first = $this->accounts->create($admin, ['name' => 'a', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->workspace->id]);

        try {
            $this->accounts->create($admin, ['name' => 'b', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->workspace->id]);
            $this->fail('quota not enforced');
        } catch (ServiceAccountException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }

        // Disabled accounts do not count.
        $this->accounts->setStatus($first, $admin, false);
        $this->accounts->create($admin, ['name' => 'b', 'scope' => ServiceAccount::SCOPE_WORKSPACE, 'workspace_id' => $this->workspace->id]);
        $this->assertSame(2, ServiceAccount::count());
    }

    public function test_grants_for_organization_account(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->accounts->create($admin, ['name' => 'org-agent', 'scope' => ServiceAccount::SCOPE_ORGANIZATION]);

        $this->accounts->setGrant($account, $admin, $this->workspace, ['configs.read', 'nope']);
        $this->assertTrue($account->can('configs.read', $this->workspace->id));
        $this->assertFalse($account->can('configs.write', $this->workspace->id));

        $this->accounts->removeGrant($account, $admin, $this->workspace);
        $this->assertFalse($account->can('configs.read', $this->workspace->id));

        $this->expectException(ServiceAccountException::class);
        $this->accounts->setGrant($account, $admin, $this->foreignWorkspace, ['configs.read']);
    }

    public function test_workspace_account_grant_cannot_be_moved(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();

        $this->accounts->setGrant($account, $admin, $this->workspace, ['configs.read']);
        $this->assertFalse($account->can('configs.write', $this->workspace->id));

        $this->expectException(ServiceAccountException::class);
        $this->accounts->setGrant($account, $admin, $this->otherWorkspace, ['configs.read']);
    }

    public function test_workspace_account_grant_cannot_be_removed(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();

        $this->expectException(ServiceAccountException::class);
        $this->accounts->removeGrant($account, $admin, $this->workspace);
    }

    public function test_issue_key_hash_only_with_clamped_expiry_and_two_key_limit(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();

        $issued = $this->accounts->issueKey($account, $admin, 'fleet-a', 9999);
        $this->assertStringStartsWith('atgsa-', $issued['plain']);
        $this->assertSame(38, strlen($issued['plain']));
        $this->assertSame(hash('sha256', $issued['plain']), $issued['token']->token);
        $this->assertNull($issued['token']->token_encrypted);
        $this->assertNull($issued['token']->user_id);
        $this->assertSame(substr($issued['plain'], -4), $issued['token']->last_four);
        $this->assertTrue($issued['token']->expires_at->between(now()->addDays(364), now()->addDays(366)));

        $this->accounts->issueKey($account, $admin, 'fleet-b');
        try {
            $this->accounts->issueKey($account, $admin, 'fleet-c');
            $this->fail('third key allowed');
        } catch (ServiceAccountException) {
        }

        $this->accounts->revokeKey($issued['token'], $admin, 'rotated');
        $this->accounts->issueKey($account, $admin, 'fleet-c');
        $this->assertSame(2, $account->activeKeys()->count());

        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%' . $issued['plain'] . '%')->exists());
    }

    public function test_revoked_key_stays_revoked(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount();
        [, $key] = $this->accountKey($account);

        $this->accounts->revokeKey($key, $admin, 'leaked');
        $key->refresh();

        $this->assertSame('revoked', $key->status);
        $this->assertNotNull($key->revoked_at);
        $this->assertSame($admin->id, (int) $key->revoked_by_user_id);
        $this->assertSame('leaked', $key->revoke_reason);
    }

    public function test_transfer_owner_must_stay_in_organization(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $other = $this->makeUser('other@example.test', 101);
        $stranger = $this->makeUser('stranger@example.test', 101, 300);
        $account = $this->makeAccount();

        $this->accounts->transferOwner($account, $admin, $other);
        $this->assertSame($other->id, (int) $account->fresh()->owner_user_id);

        $this->expectException(ServiceAccountException::class);
        $this->accounts->transferOwner($account, $admin, $stranger);
    }

    public function test_assign_system_requires_register_grant_on_its_workspace(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $system = $this->makeSystem($admin);
        $account = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->otherWorkspace);

        try {
            $this->accounts->assignSystem($system, $account, $admin);
            $this->fail('assigned outside grant');
        } catch (ServiceAccountException) {
        }

        $good = $this->makeAccount();
        $this->accounts->assignSystem($system, $good, $admin);
        $this->assertSame($good->id, (int) $system->fresh()->service_account_id);
        $this->assertTrue(ActivityLog::where('event', 'system.assigned_to_service_account')->exists());
    }
}
