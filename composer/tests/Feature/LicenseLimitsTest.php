<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Support\License;
use App\Support\Licensing\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\TestLicense;
use Tests\TestCase;

/**
 * Licence payload v2: node and user limits, the over-limit state, and feature entitlements.
 */
class LicenseLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private int $nodeSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'ops', 'status' => 'active']);
    }

    private function user(string $email, int $rbacId = 102): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email, 'email' => $email,
            'password_hash' => Hash::make('secret-pass'), 'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'), 'dob' => '1990-01-01',
            'rbac_id' => $rbacId, 'org_id' => 200, 'status' => 'active',
        ])->save();

        return $user;
    }

    /** @return array<string, string> */
    private function pat(User $user): array
    {
        $plain = PatToken::generateCustomToken();
        PatToken::create([
            'tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'cli',
            'token' => hash('sha256', $plain), 'abilities' => ['*'], 'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    private function node(User $owner, string $status = 'active'): SystemRegister
    {
        $this->nodeSeq++;

        return SystemRegister::create([
            'pat_token_id' => 1, 'user_id' => $owner->id, 'org_id' => 200, 'workspace_id' => $this->workspace->id,
            'system_name' => 'node-' . $this->nodeSeq, 'os_type' => 'linux', 'ip_address' => '10.0.0.' . $this->nodeSeq,
            'status' => $status, 'validation_hash' => 'k-' . str_repeat('a', 40) . $this->nodeSeq,
        ]);
    }

    private function register(User $user, array $headers): \Illuminate\Testing\TestResponse
    {
        $this->nodeSeq++;

        return $this->postJson('/api/v1/systems', [
            'system_name' => 'new-' . $this->nodeSeq, 'os_type' => 'linux', 'ip_address' => '10.1.0.' . $this->nodeSeq,
            'workspace_id' => $this->workspace->id, 'system_key' => 'k1-' . str_repeat('b', 40) . $this->nodeSeq,
        ], $headers);
    }

    public function test_node_registration_is_blocked_at_the_limit(): void
    {
        TestLicense::install(['max_nodes' => 2]);
        $user = $this->user('ops@acme.test');
        $this->workspace->addUser($user->id, false);
        $headers = $this->pat($user);
        $this->node($user);
        $this->node($user, 'inactive');   // deregistered nodes do not count

        $this->register($user, $headers)->assertCreated();
        $this->assertSame(2, License::nodesUsed());

        $this->register($user, $headers)
            ->assertStatus(403)
            ->assertJson(['code' => 'NODE_LIMIT_REACHED', 'message' => 'Node limit reached (2/2). Upload a license with a higher node limit.']);
        $this->assertSame(2, SystemRegister::query()->where('status', 'active')->count());
    }

    public function test_reactivating_a_node_counts_against_the_limit(): void
    {
        TestLicense::install(['max_nodes' => 1]);
        $user = $this->user('ops@acme.test');
        $this->node($user);
        $inactive = $this->node($user, 'inactive');

        $response = app(\App\Http\Controllers\Api\SystemRegisterController::class)->reactivateSystem($inactive);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('NODE_LIMIT_REACHED', $response->getData(true)['code']);
        $this->assertSame('inactive', $inactive->fresh()->status);
    }

    public function test_unlimited_licence_allows_any_number(): void
    {
        TestLicense::install(['max_nodes' => 0, 'max_users' => 0]);
        $user = $this->user('ops@acme.test');
        $this->workspace->addUser($user->id, false);
        foreach (range(1, 5) as $i) {
            $this->node($user);
        }

        $this->register($user, $this->pat($user))->assertCreated();
        $this->assertTrue(License::canAddUser());
        $this->assertSame('ok', License::usage()['nodes']['level']);
    }

    public function test_user_creation_is_blocked_at_the_user_limit(): void
    {
        TestLicense::install(['max_users' => 1]);
        $admin = $this->user('admin@acme.test', 101);

        $this->postJson('/api/users', [
            'name' => 'New', 'email' => 'new@acme.test', 'dob' => '1990-01-01', 'password' => 'password123',
        ], $this->pat($admin))
            ->assertStatus(403)
            ->assertJson(['code' => 'USER_LIMIT_REACHED', 'message' => 'User limit reached (1/1). Upload a license with a higher user limit.']);
        $this->assertDatabaseMissing('users', ['email' => 'new@acme.test']);
    }

    public function test_lower_limits_after_a_renewal_keep_everything_and_block_additions(): void
    {
        $user = $this->user('ops@acme.test');
        $this->user('second@acme.test');
        $this->user('third@acme.test');
        foreach (range(1, 3) as $i) {
            $this->node($user);
        }

        TestLicense::install(['max_nodes' => 2, 'max_users' => 2]);

        $this->assertSame(3, License::nodesUsed());
        $this->assertSame(3, License::usersUsed());
        $this->assertSame('over', License::usage()['nodes']['level']);
        $this->assertSame('over', License::usage()['users']['level']);
        $this->assertFalse(License::canAddNode());
        $this->assertFalse(License::canAddUser());
        $this->assertStringContainsString('Over the licence limit (3/2 nodes, 3/2 users)', License::banner()['text']);
    }

    public function test_usage_warning_levels(): void
    {
        $user = $this->user('ops@acme.test');
        foreach (range(1, 8) as $i) {
            $this->node($user);
        }

        TestLicense::install(['max_nodes' => 10]);
        $this->assertSame('warning', License::usage()['nodes']['level']);

        TestLicense::install(['max_nodes' => 8]);
        $this->assertSame('full', License::usage()['nodes']['level']);
    }

    public function test_v1_licence_limits_users_by_seats_and_nodes_not_at_all(): void
    {
        TestLicense::install(['format_version' => 1, 'seats' => 3]);

        $this->assertSame(0, License::maxNodes());
        $this->assertSame(3, License::maxUsers());
        $this->assertSame(TestLicense::allFeatures(), array_values(array_intersect(TestLicense::allFeatures(), License::status()['license']->features)));
    }

    public function test_settings_shows_usage_policy_and_features(): void
    {
        $admin = $this->user('super@acme.test', 100);
        TestLicense::install(['max_nodes' => 5, 'max_users' => 0, 'policy_type' => 'custom', 'policy_id' => 'custom:7', 'features' => ['sso']]);

        $this->actingAs($admin)->get(route('admin.settings', ['tab' => 'licence']))
            ->assertOk()
            ->assertSee('Custom')
            ->assertSee('0 / 5')
            ->assertSee('1 / unlimited')
            ->assertSee('data-feature="sso"', false)
            ->assertSee('Not licensed');
    }

    public function test_unlicensed_feature_routes_return_feature_not_licensed(): void
    {
        $admin = $this->user('super@acme.test', 100);
        TestLicense::install(['features' => ['sso']]);

        $this->actingAs($admin)->postJson(route('admin.notifications.store'), [])
            ->assertStatus(403)
            ->assertJson(['code' => 'FEATURE_NOT_LICENSED', 'message' => "'Notifications' is not included in your license."]);

        $this->actingAs($admin)->get(route('admin.notifications'))
            ->assertStatus(403)
            ->assertSee('Notifications is not included in your license');

        $this->assertTrue(Entitlements::isFeatureEnabled('sso'));
        $this->assertFalse(Entitlements::isFeatureEnabled('ai_connect'));
    }

    public function test_service_account_keys_need_the_feature(): void
    {
        TestLicense::install(['features' => []]);

        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer atgsa-' . str_repeat('x', 40)])
            ->assertStatus(403)
            ->assertJson(['code' => 'FEATURE_NOT_LICENSED']);
    }

    public function test_feature_overview_lists_every_catalog_feature(): void
    {
        TestLicense::install(['features' => ['sso', 'workspaces', 'unknown_future_feature']]);

        $overview = collect(Entitlements::overview())->pluck('enabled', 'key');
        $this->assertSame(TestLicense::allFeatures(), $overview->keys()->all());
        $this->assertTrue($overview['sso']);
        $this->assertTrue($overview['workspaces']);
        $this->assertFalse($overview['notifications']);
    }

    /** The shared enforcement cases (tests/Fixtures/licensing/limit-cases.json). */
    public function test_shared_limit_cases(): void
    {
        $dir = TestLicense::fixtureDir();
        $keys = json_decode((string) file_get_contents("$dir/keys.json"), true)['keys'][0];
        $verifier = new \App\Support\Licensing\Verifier(\App\Support\Licensing\Verifier::keyring([
            $keys['key_id'] => ['public_key' => $keys['ed25519_public_b64'], 'enc_key' => $keys['aes_key_hex']],
        ]), Entitlements::catalog());

        foreach (json_decode((string) file_get_contents("$dir/limit-cases.json"), true) as $case) {
            $license = $verifier->verify((string) file_get_contents("$dir/{$case['file']}"), 'startup', new \DateTimeImmutable($case['now']), '2.3.0', null);
            $nodeOk = $license->maxNodes === 0 || $case['nodes'] < $license->maxNodes;
            $userOk = $license->maxUsers === 0 || $case['users'] < $license->maxUsers;
            $over = ($license->maxNodes > 0 && $case['nodes'] > $license->maxNodes) || ($license->maxUsers > 0 && $case['users'] > $license->maxUsers);

            $this->assertSame($case['expect']['node_add'], $nodeOk ? 'ok' : 'NODE_LIMIT_REACHED', $case['name']);
            $this->assertSame($case['expect']['user_add'], $userOk ? 'ok' : 'USER_LIMIT_REACHED', $case['name']);
            $this->assertSame($case['expect']['over_limit'], $over, $case['name']);
        }
    }
}
