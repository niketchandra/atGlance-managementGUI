<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Organization;
use App\Models\PatToken;
use App\Models\User;
use App\Services\LicenseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CRITICAL 1 regression: the /api/users resource must require an admin PAT and
 * stay scoped to the caller's organisation. It used to be fully unauthenticated.
 */
class UserApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme']);
        Organization::query()->updateOrCreate(['id' => 201], ['name' => 'Other']);
    }

    private function user(string $email, int $rbacId, int $orgId = 200): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password_hash' => Hash::make('secret-pass'),
            'password' => Hash::make('secret-pass'),
            'rbac_id' => $rbacId,
            'org_id' => $orgId,
            'status' => 'active',
        ])->save();

        return $user;
    }

    /** @return array<string, string> */
    private function pat(User $user): array
    {
        $plain = PatToken::generateCustomToken();
        PatToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test',
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    public function test_index_requires_a_token(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $member = $this->user('member@acme.test', 102);
        $this->getJson('/api/users', $this->pat($member))->assertForbidden();
    }

    public function test_admin_lists_only_its_own_organisation(): void
    {
        $admin = $this->user('admin@acme.test', 101);
        $this->user('peer@acme.test', 102, 200);
        $this->user('outsider@other.test', 101, 201);

        $response = $this->getJson('/api/users', $this->pat($admin))->assertOk();

        $emails = collect($response->json())->pluck('email');
        $this->assertContains('admin@acme.test', $emails);
        $this->assertContains('peer@acme.test', $emails);
        $this->assertNotContains('outsider@other.test', $emails);
    }

    public function test_admin_cannot_read_a_user_in_another_org(): void
    {
        $admin = $this->user('admin@acme.test', 101);
        $outsider = $this->user('outsider@other.test', 102, 201);

        $this->getJson('/api/users/' . $outsider->id, $this->pat($admin))->assertForbidden();
    }

    public function test_non_admin_cannot_create_users(): void
    {
        $member = $this->user('member@acme.test', 102);
        $this->postJson('/api/users', [
            'name' => 'x', 'email' => 'new@acme.test', 'dob' => '1990-01-01', 'password' => 'password123',
        ], $this->pat($member))->assertForbidden();
    }

    public function test_admin_create_forces_its_own_org(): void
    {
        $admin = $this->user('admin@acme.test', 101);
        // store() is licence-gated; activate a licence so we reach the authorization path.
        AdminSetting::putValue('license', 'license_status', LicenseClient::VERIFIED_STATUS);
        AdminSetting::putValue('license', 'license_key', 'test-key');

        $this->postJson('/api/users', [
            'name' => 'New', 'email' => 'new@acme.test', 'dob' => '1990-01-01', 'password' => 'password123',
        ], $this->pat($admin))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'new@acme.test', 'org_id' => 200]);
    }

    public function test_super_admin_cannot_be_deleted_via_the_api(): void
    {
        $admin = $this->user('admin@acme.test', 101);
        $super = $this->user('super@acme.test', 100);

        $this->deleteJson('/api/users/' . $super->id, [], $this->pat($admin))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }
}
