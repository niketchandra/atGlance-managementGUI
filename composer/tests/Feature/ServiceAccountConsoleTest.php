<?php

namespace Tests\Feature;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountConsoleTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        $this->withoutMiddleware([\App\Http\Middleware\EnsureApplicationInstalled::class, \App\Http\Middleware\EnsureProfileSetupComplete::class]);
    }

    public function test_admin_creates_org_account_and_key_is_shown_once(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $this->actingAs($admin)->post(route('service-accounts.store'), ['name' => 'org-agent', 'scope' => 'organization'])
            ->assertRedirect();
        $account = ServiceAccount::where('name', 'org-agent')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('service-accounts.keys.store', $account->id), ['name' => 'fleet', 'days' => 30]);
        $response->assertRedirect(route('service-accounts.show', $account->id));
        $plain = session('new_service_account_key');
        $this->assertStringStartsWith('atgsa-', $plain);

        $this->actingAs($admin)->get(route('service-accounts.show', $account->id))->assertOk()->assertSee($plain);
        $this->actingAs($admin)->get(route('service-accounts.show', $account->id))->assertOk()->assertDontSee($plain);
        $this->assertNull(PatToken::where('tokenable_id', $account->id)->where('tokenable_type', ServiceAccount::class)->first()->token_encrypted);
    }

    public function test_admin_page_lists_accounts(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $this->makeAccount(attributes: ['name' => 'listed-agent']);

        $this->actingAs($admin)->get(route('service-accounts.index'))->assertOk()->assertSee('listed-agent');
    }

    public function test_member_cannot_create_or_view(): void
    {
        $member = $this->makeUser('member@example.test');
        $this->workspace->addUser($member->id);
        $account = $this->makeAccount();

        $this->actingAs($member)->post(route('service-accounts.store'), ['name' => 'x', 'scope' => 'workspace', 'workspace_id' => $this->workspace->id])->assertForbidden();
        $this->actingAs($member)->get(route('service-accounts.show', $account->id))->assertNotFound();
        $this->actingAs($member)->get(route('service-accounts.index'))->assertRedirect();
    }

    public function test_workspace_admin_with_permission_creates_workspace_account_only(): void
    {
        $wsAdmin = $this->makeUser('ws@example.test');
        $this->workspace->addUser($wsAdmin->id, true);
        $this->workspace->setPermissions($wsAdmin->id, ['service_accounts' => true]);

        $this->actingAs($wsAdmin)->post(route('service-accounts.store'), ['name' => 'ws-agent', 'scope' => 'workspace', 'workspace_id' => $this->workspace->id])->assertRedirect();
        $this->actingAs($wsAdmin)->post(route('service-accounts.store'), ['name' => 'org-agent', 'scope' => 'organization'])->assertForbidden();
        $this->actingAs($wsAdmin)->post(route('service-accounts.store'), ['name' => 'other', 'scope' => 'workspace', 'workspace_id' => $this->otherWorkspace->id])->assertForbidden();

        $this->actingAs($wsAdmin)->get(route('admin.workspaces.show', ['workspaceId' => $this->workspace->id, 'tab' => 'service-accounts']))
            ->assertOk()->assertSee('ws-agent');
    }

    public function test_other_organization_ids_are_404(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $theirs = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->foreignWorkspace);

        $this->actingAs($admin)->get(route('service-accounts.show', $theirs->id))->assertNotFound();
        $this->actingAs($admin)->post(route('service-accounts.keys.store', $theirs->id), ['name' => 'x'])->assertNotFound();
    }

    public function test_quota_error_is_shown_not_500(): void
    {
        ServiceAccountSettings::save(['sa_org_quota' => 0]);
        $admin = $this->makeUser('admin@example.test', 101);

        $this->actingAs($admin)->from(route('service-accounts.index'))
            ->post(route('service-accounts.store'), ['name' => 'x', 'scope' => 'organization'])
            ->assertRedirect(route('service-accounts.index'))
            ->assertSessionHasErrors('service_account');
    }

    public function test_revoke_key_and_grant_from_console(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        [, $key] = $this->accountKey($account);

        $this->actingAs($admin)->post(route('service-accounts.grants.store', $account->id), ['workspace_id' => $this->workspace->id, 'permissions' => ['configs.read']])->assertRedirect();
        $this->assertTrue($account->fresh()->can('configs.read', $this->workspace->id));

        $this->actingAs($admin)->post(route('service-accounts.keys.revoke', [$account->id, $key->id]), ['reason' => 'leaked'])->assertRedirect();
        $this->assertSame('revoked', $key->fresh()->status);
    }

    public function test_assign_system_to_service_account(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $this->workspace->addUser($admin->id, true);
        $system = $this->makeSystem($admin);
        $account = $this->makeAccount();

        $this->actingAs($admin)->get(route('systems-registered'))->assertOk()->assertSee($account->name);
        $this->actingAs($admin)->post(route('systems-registered.service-account', $system->id), ['service_account_id' => $account->id])->assertRedirect();
        $this->assertSame($account->id, (int) $system->fresh()->service_account_id);
    }

    public function test_settings_update_by_admin(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);

        $this->actingAs($admin)->put(route('service-accounts.settings'), [
            'sa_default_key_days' => 30, 'sa_max_key_days' => 180, 'sa_org_quota' => 3, 'sa_workspace_quota' => 2,
            'sa_expiry_notice_days' => '7,1', 'sa_require_for_registration' => '1',
            'pat_default_key_days' => 30, 'pat_max_key_days' => 120, 'pat_user_quota' => 3,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(180, ServiceAccountSettings::maxKeyDays());
        $this->assertSame(120, ServiceAccountSettings::patMaxKeyDays());
        $this->assertSame(3, ServiceAccountSettings::patUserQuota());
        $this->assertTrue(ServiceAccountSettings::requireForRegistration());
    }
}
