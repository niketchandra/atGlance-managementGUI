<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Support\ServiceAccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountAccessTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_admins_manage_organization_accounts_members_do_not(): void
    {
        $this->assertTrue(ServiceAccountAccess::canCreateOrganizationAccount($this->makeUser('sa@example.test', 100)));
        $this->assertTrue(ServiceAccountAccess::canCreateOrganizationAccount($this->makeUser('a@example.test', 101)));
        $this->assertFalse(ServiceAccountAccess::canCreateOrganizationAccount($this->makeUser('u@example.test', 102)));
    }

    public function test_workspace_admin_needs_service_accounts_permission(): void
    {
        $wsAdmin = $this->makeUser('wsadmin@example.test');
        $this->workspace->addUser($wsAdmin->id, true);

        $this->assertFalse(ServiceAccountAccess::canManageWorkspaceAccounts($wsAdmin, $this->workspace));

        $this->workspace->setPermissions($wsAdmin->id, ['service_accounts' => true]);
        $this->assertTrue(ServiceAccountAccess::canManageWorkspaceAccounts($wsAdmin, $this->workspace->fresh()));
        $this->assertFalse(ServiceAccountAccess::canManageWorkspaceAccounts($wsAdmin, $this->otherWorkspace));
        $this->assertFalse(ServiceAccountAccess::canCreateOrganizationAccount($wsAdmin));
    }

    public function test_member_cannot_manage(): void
    {
        $member = $this->makeUser('member@example.test');
        $this->workspace->addUser($member->id, false);

        $this->assertFalse(ServiceAccountAccess::canManageWorkspaceAccounts($member, $this->workspace));
        $this->assertFalse(ServiceAccountAccess::canManage($member, $this->makeAccount()));
    }

    public function test_workspace_admin_cannot_manage_or_grant_organization_accounts(): void
    {
        $wsAdmin = $this->makeUser('wsadmin@example.test');
        $this->workspace->addUser($wsAdmin->id, true);
        $this->workspace->setPermissions($wsAdmin->id, ['service_accounts' => true]);
        $orgAccount = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);

        $this->assertFalse(ServiceAccountAccess::canManage($wsAdmin, $orgAccount));
        $this->assertFalse(ServiceAccountAccess::canGrant($wsAdmin, $orgAccount, $this->workspace));
    }

    public function test_other_organization_is_invisible(): void
    {
        $admin = $this->makeUser('a@example.test', 101);
        $theirs = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->foreignWorkspace);

        $this->assertNull(ServiceAccountAccess::findForActor($admin, $theirs->id));
        $this->assertFalse(ServiceAccountAccess::canManage($admin, $theirs));
        $this->assertSame(0, ServiceAccountAccess::visibleQuery($admin)->whereKey($theirs->id)->count());
    }

    public function test_admin_of_org_sees_both_scopes(): void
    {
        $admin = $this->makeUser('a@example.test', 101);
        $ws = $this->makeAccount();
        $org = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);

        $this->assertEqualsCanonicalizing([$ws->id, $org->id], ServiceAccountAccess::visibleQuery($admin)->pluck('id')->all());
    }
}
