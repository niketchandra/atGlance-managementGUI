<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProfileOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class ProfileOverviewTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private Workspace $ansible;
    private Workspace $other;
    private int $nextSystemId = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        $this->ansible = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
        $this->other = Workspace::create(['org_id' => 200, 'name' => 'kubernetes', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function makeUser(string $email, int $rbac = 102): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password_hash' => Hash::make('secret-pass'),
            'password' => Hash::make('secret-pass'),
            'rbac_id' => $rbac,
            'org_id' => 200,
            'status' => 'active',
            'dob' => '1990-01-01',
            'pin' => Hash::make('12345'),
        ])->save();

        return $user;
    }

    private function system(User $owner, ?Workspace $workspace, string $status = 'active'): int
    {
        $id = $this->nextSystemId++;
        DB::table('system_register')->insert([
            'id' => $id,
            'pat_token_id' => 1,
            'user_id' => $owner->id,
            'org_id' => 200,
            'workspace_id' => $workspace?->id ?? 0,
            'system_name' => 'host-' . $id,
            'os_type' => 'linux',
            'ip_address' => '10.0.0.' . ($id % 250),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function file(User $owner, int $systemId, string $name, string $createdAt): void
    {
        DB::table('configuration_files')->insert([
            'user_id' => $owner->id,
            'system_register_id' => $systemId,
            'file_name' => $name,
            'service_name' => 'nginx',
            'storage_disk' => 'local',
            'file_location' => "config_files/{$systemId}/{$name}",
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function apiKey(User $owner, string $status = 'active', ?string $expiresAt = '2099-12-31 23:59:59'): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $owner->id,
            'user_id' => $owner->id,
            'name' => 'key',
            'token' => hash('sha256', uniqid('', true)),
            'status' => $status,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_user_sees_only_their_own_usage(): void
    {
        $user = $this->makeUser('me@example.test');
        $colleague = $this->makeUser('colleague@example.test');
        $this->ansible->addUser($user->id);
        $this->ansible->addUser($colleague->id);

        $web = $this->system($user, $this->ansible);
        $this->system($user, $this->ansible, 'inactive');
        $theirs = $this->system($colleague, $this->ansible);

        // Two uploads of nginx.conf are one file with two versions.
        $this->file($user, $web, 'nginx.conf', '2026-09-01 10:00:00');
        $this->file($user, $web, 'nginx.conf', '2026-09-20 10:00:00');
        $this->file($user, $web, 'sshd_config', '2026-09-10 10:00:00');
        $this->file($colleague, $theirs, 'nginx.conf', '2026-09-25 10:00:00');

        $this->apiKey($user);
        $this->apiKey($user, 'revoked');
        $this->apiKey($user, 'active', '2020-01-01 00:00:00');
        $this->apiKey($colleague);

        $overview = ProfileOverview::for($user);

        $this->assertSame(1, $overview['mine']['systems_active']);
        $this->assertSame(1, $overview['mine']['systems_inactive']);
        $this->assertSame(2, $overview['mine']['files']);
        $this->assertSame(3, $overview['mine']['versions']);
        $this->assertSame('2026-09-20 10:00:00', $overview['mine']['latest_backup']->format('Y-m-d H:i:s'));
        $this->assertSame(1, $overview['mine']['api_keys']);
        $this->assertNull($overview['scope'], 'Regular users have no admin scope');
    }

    public function test_workspace_admin_scope_covers_only_administered_workspaces(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $member = $this->makeUser('member@example.test');
        $outsider = $this->makeUser('outsider@example.test');
        $this->ansible->addUser($admin->id, true);
        $this->ansible->addUser($member->id);
        // A member (not admin) of another workspace: not in scope.
        $this->other->addUser($admin->id);
        $this->other->addUser($outsider->id);

        $inScope = $this->system($member, $this->ansible);
        $outOfScope = $this->system($outsider, $this->other);
        $this->file($member, $inScope, 'nginx.conf', '2026-09-20 10:00:00');
        $this->file($outsider, $outOfScope, 'nginx.conf', '2026-09-25 10:00:00');

        $scope = ProfileOverview::for($admin)['scope'];

        $this->assertSame('Your workspace', $scope['label']);
        $this->assertSame(1, $scope['workspaces']);
        $this->assertSame(2, $scope['users']);
        $this->assertSame(1, $scope['systems_active']);
        $this->assertSame(1, $scope['files']);
        $this->assertSame('2026-09-20 10:00:00', $scope['latest_backup']->format('Y-m-d H:i:s'));
    }

    public function test_admin_without_workspaces_gets_empty_scope(): void
    {
        $admin = $this->makeUser('lonely@example.test', 101);
        $this->system($this->makeUser('x@example.test'), $this->ansible);

        $scope = ProfileOverview::for($admin)['scope'];

        $this->assertSame(0, $scope['workspaces']);
        $this->assertSame(0, $scope['systems_active']);
        $this->assertSame('You do not administer any workspace yet.', $scope['description']);
    }

    public function test_super_admin_scope_is_the_organization(): void
    {
        $super = $this->makeUser('super@example.test', 100);
        $a = $this->makeUser('a@example.test');
        $b = $this->makeUser('b@example.test');
        $this->file($a, $this->system($a, $this->ansible), 'nginx.conf', '2026-09-20 10:00:00');
        $this->file($b, $this->system($b, $this->other), 'nginx.conf', '2026-09-21 10:00:00');
        $this->system($b, null, 'inactive');

        $scope = ProfileOverview::for($super)['scope'];

        $this->assertSame('Organization', $scope['label']);
        $this->assertSame(2, $scope['workspaces']);
        $this->assertSame(3, $scope['users']);
        $this->assertSame(2, $scope['systems_active']);
        $this->assertSame(2, $scope['files']);
    }

    public function test_profile_page_shows_real_values_and_no_placeholders(): void
    {
        $user = $this->actingAsRole(102);
        $user->forceFill(['first_name' => 'Niket', 'last_name' => 'Chandrawanshi', 'created_at' => '2026-05-04 09:00:00'])->save();
        $this->file($user, $this->system($user, $this->ansible), 'nginx.conf', '2026-09-20 10:00:00');
        $this->apiKey($user);

        $response = $this->get(route('profile'))->assertOk();

        $response->assertSee('Niket Chandrawanshi')
            ->assertSee('May 4, 2026')
            ->assertSee('Role')
            ->assertSee('Systems registered')
            ->assertSee('Active API keys');

        foreach (['3.2M', '99.8%', '245ms', 'Professional', 'March 1, 2026', '>Last Login<', 'Active &amp; Verified'] as $placeholder) {
            $response->assertDontSee($placeholder, false);
        }
    }

    public function test_new_user_sees_empty_state(): void
    {
        $this->actingAsRole(102);

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('No systems registered yet.');
    }

    public function test_admin_profile_shows_scope_section(): void
    {
        $admin = $this->actingAsRole(101);
        $this->ansible->addUser($admin->id, true);

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Your workspace')
            ->assertSee('Workspaces where you are a workspace admin.');
    }
}
