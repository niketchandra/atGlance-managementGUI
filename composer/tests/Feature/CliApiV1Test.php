<?php

namespace Tests\Feature;

use App\Models\ConfigurationFile;
use App\Models\Organization;
use App\Models\PatToken;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The /api/v1 API for the atglance CLI v2, and the two security fixes in the
 * shared legacy code it reuses (force actions, config upload).
 */
class CliApiV1Test extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'k1-0123456789abcdef0123456789abcdef01234567';

    private Workspace $workspace;
    private Workspace $foreignWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Storage::fake('local');

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        Organization::query()->updateOrCreate(['id' => 300], ['name' => 'Other Org']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
        $this->foreignWorkspace = Workspace::create(['org_id' => 300, 'name' => 'theirs', 'status' => 'active']);
    }

    private function user(string $email, int $rbacId = 102, int $orgId = 200, string $status = 'active'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password_hash' => Hash::make('secret-pass'),
            'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => $rbacId,
            'org_id' => $orgId,
            'status' => $status,
        ])->save();

        return $user;
    }

    /**
     * @return array<string, string> Authorization header for a new PAT of $user
     */
    private function pat(User $user, ?PatToken &$token = null): array
    {
        $plain = PatToken::generateCustomToken();
        $token = PatToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'cli',
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    private function system(User $owner, array $overrides = []): SystemRegister
    {
        return SystemRegister::create(array_merge([
            'pat_token_id' => 1,
            'user_id' => $owner->id,
            'org_id' => $owner->org_id,
            'workspace_id' => $this->workspace->id,
            'system_name' => 'web-01',
            'os_type' => 'linux',
            'distro' => 'ubuntu',
            'version' => '24.04',
            'tags' => 'prod',
            'ip_address' => '10.0.0.5',
            'status' => 'active',
            'validation_hash' => self::KEY,
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    private function systemHeaders(SystemRegister $system, string $key = self::KEY): array
    {
        return ['X-Atglance-System-Id' => (string) $system->id, 'X-Atglance-System-Key' => $key];
    }

    private function configFile(string $name = 'sshd_config', string $content = "PermitRootLogin no\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    // ---------------------------------------------------------------- legacy security fixes

    public function test_legacy_force_deregister_by_admin_of_same_org(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $system = $this->system($owner);

        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $admin->email, 'password' => 'secret-pass'])
            ->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame('inactive', $system->fresh()->status);
    }

    public function test_legacy_force_reactivate_by_super_admin_of_other_org(): void
    {
        $owner = $this->user('owner@example.test');
        $super = $this->user('super@example.test', 100, 300);
        $system = $this->system($owner, ['status' => 'inactive']);

        $this->postJson('/api/system-reactivate-force', ['system_id' => $system->id, 'email' => $super->email, 'pin' => '1234'])
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_legacy_force_rejects_non_admin_and_admin_of_other_org(): void
    {
        $owner = $this->user('owner@example.test');
        $teammate = $this->user('mate@example.test');
        $foreignAdmin = $this->user('fadmin@example.test', 101, 300);
        $system = $this->system($owner);
        $message = "Only an admin of the system's organisation can force this action";

        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $owner->email, 'password' => 'secret-pass'])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $teammate->email, 'password' => 'secret-pass'])
            ->assertForbidden();
        $this->postJson('/api/system-reactivate-force', ['system_id' => $system->id, 'email' => $foreignAdmin->email, 'password' => 'secret-pass'])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->assertSame('active', $system->fresh()->status);
    }

    public function test_legacy_force_keeps_credential_errors(): void
    {
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test', 101);
        $system = $this->system($owner);

        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $admin->email, 'password' => 'wrong'])
            ->assertUnauthorized();
        $this->postJson('/api/system-deregister-force', ['system_id' => $system->id, 'email' => $admin->email])
            ->assertStatus(422);
    }

    public function test_legacy_upload_to_system_of_other_org_is_not_found(): void
    {
        $foreignOwner = $this->user('them@example.test', 102, 300);
        $foreignSystem = $this->system($foreignOwner, ['workspace_id' => $this->foreignWorkspace->id]);
        $me = $this->user('me@example.test');

        $this->post('/api/config-files/upload', [
            'file' => $this->configFile(),
            'system_id' => $foreignSystem->id,
            'service_name' => 'ssh',
        ], $this->pat($me) + ['Accept' => 'application/json'])
            ->assertNotFound()->assertJsonPath('message', 'System not found');

        $this->assertSame(0, ConfigurationFile::count());
    }

    public function test_legacy_upload_by_same_org_teammate_still_works(): void
    {
        $owner = $this->user('owner@example.test');
        $teammate = $this->user('mate@example.test');
        $system = $this->system($owner);

        $this->post('/api/config-files/upload', [
            'file' => $this->configFile(),
            'system_id' => $system->id,
            'service_name' => 'ssh',
        ], $this->pat($teammate) + ['Accept' => 'application/json'])
            ->assertCreated();
    }
}
