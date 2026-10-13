<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountModelTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_workspace_account_can_act_only_in_its_workspace(): void
    {
        $account = $this->makeAccount();

        $this->assertTrue($account->can('configs.write', $this->workspace->id));
        $this->assertFalse($account->can('configs.write', $this->otherWorkspace->id));
        $this->assertFalse($account->can('systems.rotate_key', $this->workspace->id));
    }

    public function test_organization_account_needs_an_active_grant(): void
    {
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->assertFalse($account->can('configs.read', $this->workspace->id));

        $this->grant($account, $this->workspace, ['configs.read']);
        $this->assertTrue($account->fresh()->can('configs.read', $this->workspace->id));
        $this->assertFalse($account->fresh()->can('configs.write', $this->workspace->id));

        $account->grants()->update(['status' => 'revoked']);
        $this->assertFalse($account->fresh()->can('configs.read', $this->workspace->id));
    }

    public function test_disabled_account_can_do_nothing(): void
    {
        $account = $this->makeAccount();
        $account->update(['status' => 'disabled']);

        $this->assertFalse($account->fresh()->can('configs.write', $this->workspace->id));
    }

    public function test_permissions_are_normalized(): void
    {
        $this->assertSame(['configs.write', 'configs.read'], ServiceAccount::normalizePermissions(['configs.read', 'bogus', 'configs.write', 'configs.read']));
        $this->assertSame([], ServiceAccount::normalizePermissions('configs.read'));
    }

    public function test_scope_and_workspace_cannot_change_after_create(): void
    {
        $account = $this->makeAccount();
        $account->fill(['scope' => ServiceAccount::SCOPE_ORGANIZATION, 'workspace_id' => $this->otherWorkspace->id, 'org_id' => 300])->save();

        $fresh = $account->fresh();
        $this->assertSame(ServiceAccount::SCOPE_WORKSPACE, $fresh->scope);
        $this->assertSame($this->workspace->id, (int) $fresh->workspace_id);
        $this->assertSame(200, (int) $fresh->org_id);
    }

    public function test_settings_defaults_and_clamping(): void
    {
        $this->assertSame(90, ServiceAccountSettings::defaultKeyDays());
        $this->assertSame(365, ServiceAccountSettings::maxKeyDays());
        $this->assertSame(10, ServiceAccountSettings::orgQuota());
        $this->assertSame(5, ServiceAccountSettings::workspaceQuota());
        $this->assertSame([14, 3], ServiceAccountSettings::noticeDays());
        $this->assertFalse(ServiceAccountSettings::requireForRegistration());

        ServiceAccountSettings::save(['sa_max_key_days' => 5000, 'sa_expiry_notice_days' => '3, 30,x', 'sa_require_for_registration' => true]);
        $this->assertSame(3650, ServiceAccountSettings::maxKeyDays());
        $this->assertSame([30, 3], ServiceAccountSettings::noticeDays());
        $this->assertTrue(ServiceAccountSettings::requireForRegistration());
    }
}
